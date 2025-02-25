<?php

namespace DDTrace\Tests\Common;

use DDTrace\Encoders\MessagePack;
use DDTrace\Encoders\SpanEncoder;
use DDTrace\GlobalTracer;
use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\SpanData;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Tests\WebServer;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use Exception;
use PHPUnit\Framework\TestCase;

class FakeSpan extends Span
{
    public $startTime;
    public $duration;
}

trait TracerTestTrait
{
    protected static $agentRequestDumperUrl = 'http://request-replayer';
    protected static $testAgentUrl = 'http://test-agent:9126';

    public function resetTracer($tracer = null, $config = [])
    {
        // Reset the current C-level array of generated spans
        dd_trace_serialize_closed_spans();
        $transport = new DebugTransport();
        $headers = $transport->getHeaders();
        $dd_header_with_env = getHeaderWithEnvironment();
        if ($dd_header_with_env) {
            $transport->setHeader("X-Datadog-Trace-Env-Variables", $dd_header_with_env);
        }
        $tracer = $tracer ?: new Tracer($transport, null, $config);
        GlobalTracer::set($tracer);
    }

    /**
     * @param $fn
     * @param null $tracer
     * @return array[]
     */
    public function isolateTracer($fn, $tracer = null, $config = [])
    {
        $this->resetTracer($tracer, $config);

        $tracer = GlobalTracer::get();
        if (\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN')) {
            $tracer->startRootSpan("root span");
        }
        $fn($tracer);

        $traces = $this->flushAndGetTraces();
        if (!empty($traces)) {
            $this->sendTracesToTestAgent($traces);
        }
        return $traces;
    }

    public function sendTracesToTestAgent($traces)
    {
        // The data to be sent in the POST request
        $data_json = json_encode($traces);

        // The headers to be included in the request
        $headers = array(
            'Content-Type: application/json',
            'Datadog-Meta-Lang: php',
            'X-Datadog-Agent-Proxy-Disabled: true',
            'X-Datadog-Trace-Count: ' . count($traces)
        );

        // add environment variables to headers
        $dd_header_with_env = getHeaderWithEnvironment();
        if ($dd_header_with_env) {
            $headers[] = "X-Datadog-Trace-Env-Variables: " . $dd_header_with_env;
        }

        // Initialize a cURL session
        $curl = curl_init();

        // Set the cURL options
        curl_setopt($curl, CURLOPT_URL, 'http://test-agent:9126/v0.4/traces'); // The URL to send the request to
        curl_setopt($curl, CURLOPT_POST, true); // Use POST method
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_json); // Set the POST data
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return the response instead of outputting it
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); // Set the headers

        // Execute the cURL session
        $response = curl_exec($curl);

        // Close the cURL session
        curl_close($curl);

        // Output the response for debugging purposes
        // echo $response;
    }

    /**
     * @param $fn
     * @param null $tracer
     * @return array[]
     */
    public function inRootSpan($fn, $tracer = null)
    {
        // Reset the current C-level array of generated spans
        dd_trace_serialize_closed_spans();
        $transport = new DebugTransport();
        $tracer = $tracer ?: new Tracer($transport);
        GlobalTracer::set($tracer);

        $scope = $tracer->startRootSpan('root_span');
        $fn($tracer);
        $scope->close();

        return $this->flushAndGetTraces();
    }

    /**
     * @param $fn
     * @param null $tracer
     * @return array[]
     */
    public function isolateLimitedTracer($fn, $tracer = null)
    {
        // Reset the current C-level array of generated spans
        dd_trace_serialize_closed_spans();
        self::putenv('DD_TRACE_SPANS_LIMIT=0');
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN=0');
        dd_trace_internal_fn('ddtrace_reload_config');

        $transport = new DebugTransport();
        $tracer = $tracer ?: new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);

        $traces = $this->flushAndGetTraces();

        self::putenv('DD_TRACE_SPANS_LIMIT');
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN');
        dd_trace_internal_fn('ddtrace_reload_config');

        return $traces;
    }

    /**
     * This method can be used to request data to a real request dumper and to rebuild the traces
     * based on the dumped data.
     *
     * @param $fn
     * @param null $tracer
     * @return array[]
     * @throws \Exception
     */
    public function simulateAgent($fn, $tracer = null)
    {
        // Clearing existing dumped file
        $this->resetRequestDumper();

        // Reset the current C-level array of generated spans
        dd_trace_serialize_closed_spans();

        $transport = new Http(new MessagePack(), ['endpoint' => self::$testAgentUrl . "/v0.4/traces"]);

        /* Disable Expect: 100-Continue that automatically gets added by curl,
         * as it adds a 1s delay, causing tests to sometimes fail.
         */
        $transport->setHeader('Expect', '');

        $tracer = $tracer ?: new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);
        /** @var Tracer $tracer */
        $tracer = GlobalTracer::get();
        /** @var DebugTransport $transport */
        $tracer->flush();

        return $this->parseTracesFromDumpedData();
    }

    /**
     * This method executes a request into an ad-hoc web server configured with the provided envs and inis that is
     * created and destroyed with the scope of this test.
     */
    public function inWebServer($fn, $rootPath, $envs = [], $inis = [], &$curlInfo = null)
    {
        $retries = 1;
        do {
            $this->resetTracer();
            $webServer = new WebServer($rootPath, '0.0.0.0', 6666);
            $webServer->mergeEnvs($envs);
            $webServer->mergeInis($inis);
            $webServer->start();
            $this->resetRequestDumper();

            $fn(function (RequestSpec $request) use ($webServer, &$curlInfo) {
                if ($request instanceof GetSpec) {
                    $curl = curl_init('http://127.0.0.1:6666' . $request->getPath());
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $request->getHeaders());
                    $response = curl_exec($curl);
                    if (\is_array($curlInfo)) {
                        $curlInfo = \array_merge($curlInfo, \curl_getinfo($curl));
                    }
                    \curl_close($curl);
                    $webServer->stop();
                    return $response;
                }

                $webServer->stop();
                throw new Exception('Spec type not supported.');
            });

            $traces = $this->parseTracesFromDumpedData();

            if ($traces || $retries-- <= 0) {
                return $traces;
            }
        } while (true);
    }

    /**
     * This method executes a single script with the provided configuration.
     */
    public function inCli($scriptPath, $customEnvs = [], $customInis = [], $arguments = '', $withOutput = false)
    {
        $this->resetRequestDumper();
        $output = $this->executeCli($scriptPath, $customEnvs, $customInis, $arguments, $withOutput);
        $out = [$this->parseTracesFromDumpedData()];
        if ($withOutput) {
            $out[] = $output;
        }
        return $out;
    }

    public function executeCli($scriptPath, $customEnvs = [], $customInis = [], $arguments = '', $withOutput = false)
    {
        $envs = (string) new EnvSerializer(array_merge(
            [
                'DD_AUTOLOAD_NO_COMPILE' => getenv('DD_AUTOLOAD_NO_COMPILE'),
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_AGENT_HOST' => 'test-agent',
                'DD_TRACE_AGENT_PORT' => '9126',
                // Uncomment to see debug-level messages
                //'DD_TRACE_DEBUG' => 'true',
            ],
            $customEnvs
        ));
        $inis = (string) new IniSerializer(array_merge(
            [
                'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
            ],
            $customInis
        ));

        $script = escapeshellarg($scriptPath);
        $arguments = escapeshellarg($arguments);
        $commandToExecute = "$envs php $inis $script $arguments";
        if ($withOutput) {
            return (string) `$commandToExecute 2>&1`;
        } else {
            `$commandToExecute`;
        }
    }

    /**
     * Reset the request dumper removing all the dumped  data file.
     */
    public function resetRequestDumper()
    {
        $curl = curl_init(self::$agentRequestDumperUrl . '/clear-dumped-data');
        curl_exec($curl);
    }

    /**
     * This method can be used to request data to a real request dumper and to rebuild the traces
     * based on the dumped data.
     *
     * @param $fn
     * @param null $tracer
     * @return array[]
     * @throws \Exception
     */
    public function tracesFromWebRequest($fn, $tracer = null)
    {
        if ($tracer === null) {
            // Avoid phpunits default spans from being acknowledged for distributed tracing
            $this->resetTracer();
        }

        // Clearing existing dumped file
        $this->resetRequestDumper();

        // The we server has to be configured to send traces to the provided requests dumper.
        $fn($tracer);

        return $this->parseTracesFromDumpedData();
    }

    private function parseRawDumpedTraces($rawTraces)
    {
        $traces = [];

        foreach ($rawTraces as $spansInTrace) {
            $spans = [];
            foreach ($spansInTrace as $rawSpan) {
                $spanContext = new SpanContext(
                    (string) $rawSpan['trace_id'],
                    (string) $rawSpan['span_id'],
                    isset($rawSpan['parent_id']) ? (string) $rawSpan['parent_id'] : null
                );

                if (empty($rawSpan['resource'])) {
                    TestCase::fail(sprintf("Span '%s' has empty resource name", $rawSpan['name']));
                    return;
                }

                if ($rawSpan['trace_id'] == "0") {
                    TestCase::fail(sprintf("Span '%s' has zero trace_id", $rawSpan['name']));
                    return;
                }

                $rawSpan["duration"] = (int)($rawSpan["duration"] / 1000);
                $rawSpan["start"] = (int)($rawSpan["start"] / 1000);

                $internalSpan = new SpanData();
                $internalSpan->name = $rawSpan["name"];
                $internalSpan->service = isset($rawSpan['service']) ? $rawSpan['service'] : null;
                $internalSpan->resource = $rawSpan['resource'];
                if (isset($rawSpan['type'])) {
                    $internalSpan->type = $rawSpan['type'];
                }
                $internalSpan->meta = isset($rawSpan['meta']) ? $rawSpan['meta'] : [];
                $internalSpan->metrics = isset($rawSpan['metrics']) ? $rawSpan['metrics'] : [];
                $span = new FakeSpan($internalSpan, $spanContext);
                $span->duration = $rawSpan["duration"];
                $span->startTime = $rawSpan["start"];
                $this->setRawPropertyFromArray($span, $rawSpan, 'hasError', 'error', function ($value) {
                    return $value == 1 || $value == true;
                });

                $spans[] = SpanEncoder::encode($span);
            }
            $traces[] = $spans;
        }

        return $traces;
    }

    /**
     * Parses the data dumped by the fake agent and returns the parsed traces.
     *
     * @return array
     * @throws \Exception
     */
    private function parseTracesFromDumpedData()
    {
        $loaded = $this->retrieveDumpedTraceData();
        if (!$loaded) {
            return [];
        }

        if (count($loaded) > 1) {
            // There are multiple bodies. Parse them all and return them.
            $dumps = [];
            foreach ($loaded as $dump) {
                if (!isset($dump['body'])) {
                    $dumps[] = [];
                } else {
                    $dumps[] = $this->parseRawDumpedTraces(json_decode($dump['body'], true));
                }
            }

            return $dumps;
        }

        $uniqueRequest = $loaded[0];

        if (!isset($uniqueRequest['body'])) {
            return [];
        }

        $rawTraces = json_decode($uniqueRequest['body'], true);
        return $this->parseRawDumpedTraces($rawTraces);
    }

    public function parseMultipleRequestsFromDumpedData()
    {
        $manyRequests = $this->retrieveDumpedTraceData();
        if (!$manyRequests) {
            return [];
        }

        // For now we only support asserting traces against one dump at a time.
        $tracesAllRequests = [];

        // We receive back an array of traces
        foreach ($manyRequests as $uniqueRequest) {
            // error_log('Request: ' . print_r($uniqueRequest, 1));
            $rawTraces = json_decode($uniqueRequest['body'], true);
            $tracesAllRequests[] = $this->parseRawDumpedTraces($rawTraces);
        }

        return $tracesAllRequests;
    }

    /**
     * Returns the raw response body, if any, or null otherwise.
     */
    public function retrieveDumpedData()
    {
        $allResponses = [];

        // When tests run with the background sender enabled, there might be some delay between when a trace is flushed
        // and actually sent. While we should find a smart way to tackle this, for now we do it quick and dirty, in a
        // for loop.
        for ($attemptNumber = 1; $attemptNumber <= 20; $attemptNumber++) {
            $curl = curl_init(self::$agentRequestDumperUrl . '/replay');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            // Retrieving data
            $response = curl_exec($curl);
            if (!$response) {
                // PHP-FPM requests are much slower in the container
                // Temporary workaround until we get a proper test runner
                \usleep(
                    'fpm-fcgi' === \getenv('DD_TRACE_TEST_SAPI')
                        ? 500 * 1000 // 500 ms for PHP-FPM
                        : 50 * 1000 // 50 ms for other SAPIs
                );
                continue;
            } else {
                $loaded = json_decode($response, true);
                array_push($allResponses, ...$loaded);
                foreach ($loaded as $request) {
                    if (strpos($request["uri"] ?? "", "/telemetry/") !== 0) {
                        break 2;
                    }
                }
            }
        }
        return $allResponses;
    }

    public function retrieveDumpedTraceData()
    {
        return array_values(array_filter($this->retrieveDumpedData(), function ($request) {
            return strpos($request["uri"] ?? "", "/telemetry/") !== 0;
        }));
    }

    /**
     * Set a property into an object from an array optionally applying a converter.
     *
     * @param $obj
     * @param array $data
     * @param string $property
     * @param string|null $field
     * @param mixed|null $converter
     */
    private function setRawPropertyFromArray($obj, array $data, $property, $field = null, $converter = null)
    {
        $field = $field ?: $property;

        if (!isset($data[$field])) {
            return;
        }

        $reflection = new \ReflectionObject($obj);
        $property = $reflection->getProperty($property);
        $convertedValue = $converter ? $converter($data[$field]) : $data[$field];
        if ($property->isPrivate() || $property->isProtected()) {
            $property->setAccessible(true);
            $property->setValue($obj, $convertedValue);
            $property->setAccessible(false);
        } else {
            $property->setValue($obj, $convertedValue);
        }
    }

    /**
     * @param \Closure $fn
     * @return array[]
     */
    public function simulateWebRequestTracer($fn)
    {
        $tracer = GlobalTracer::get();

        $fn($tracer);

        // We have to close the active span for web frameworks, this is what is typically done in
        // `register_shutdown_function`.
        // We need yet to find a strategy, though, to make sure that the `register_shutdown_function` is actually there
        // and that do not magically disappear. Here we are faking things.
        $tracer->getActiveSpan()->finish();

        return $this->flushAndGetTraces();
    }

    /**
     * @return array[]
     */
    protected function flushAndGetTraces()
    {
        $traces = \dd_trace_serialize_closed_spans();
        return $traces ? [$traces] : [];
    }

    /**
     * @param $name string
     * @param $fn
     * @return array[]
     */
    public function inTestScope($name, $fn)
    {
        return $this->isolateTracer(function ($tracer) use ($fn, $name) {
            $scope = $tracer->startActiveSpan($name);
            $fn($tracer);
            $scope->close();
        });
    }

    /**
     * Extracts traces from a real tracer using reflection.
     *
     * @param Tracer $tracer
     * @return array
     */
    private function readTraces(Tracer $tracer)
    {
        // Extracting traces
        $tracerReflection = new \ReflectionObject($tracer);
        $tracesProperty = $tracerReflection->getProperty('traces');
        $tracesProperty->setAccessible(true);
        return $tracesProperty->getValue($tracer);
    }
}


function getHeaderWithEnvironment()
{
    try {
        $env = getenv();
    } catch (Exception $e) {
        $env = $_ENV;
    }
    $ddEnvVars = array_filter($env, function ($key) {
        return strpos($key, 'DD_') === 0;
    }, ARRAY_FILTER_USE_KEY);

    if (count($ddEnvVars) > 0) {
        $ddEnvVarsString = implode(',', array_map(function ($key, $value) {
            return "$key=$value";
        }, array_keys($ddEnvVars), $ddEnvVars));
    }
    $peer_service_enabled = isset($env['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED'])
        ? $env['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED'] : 'false';
    if ($peer_service_enabled === 'true') {
        if (!isset($env['DD_TRACE_SPAN_ATTRIBUTE_SCHEMA'])) {
            $ddEnvVarsString .= ',DD_TRACE_SPAN_ATTRIBUTE_SCHEMA=v0.5';
        }
    }
    return $ddEnvVarsString;
}
