<?php
return [
    // HTTP client
    GuzzleHttp\Client::class => function (Psr\Log\LoggerInterface $logger) {
        $stack = GuzzleHttp\HandlerStack::create();

        $stack->unshift(
            function (callable $handler) {
                return function (Psr\Http\Message\RequestInterface $request, array $options) use ($handler) {
                    $options[GuzzleHttp\RequestOptions::VERIFY] = Composer\CaBundle\CaBundle::getSystemCaRootBundlePath(
                    );
                    return $handler($request, $options);
                };
            },
            'ssl_verify'
        );

        $stack->push(
            GuzzleHttp\Middleware::log(
                $logger,
                new GuzzleHttp\MessageFormatter('HTTP client {method} call to {uri} produced response {code}'),
                Psr\Log\LogLevel::DEBUG
            )
        );

        return new GuzzleHttp\Client(
            [
                'handler' => $stack,
                GuzzleHttp\RequestOptions::HTTP_ERRORS => false,
                GuzzleHttp\RequestOptions::TIMEOUT => 3.0,
            ]
        );
    },

    // Console
    Symfony\Component\Console\Application::class => function (DI\Container $di, Psr\Log\LoggerInterface $logger) {
        $eventDispatcher = new Symfony\Component\EventDispatcher\EventDispatcher();
        $eventDispatcher->addSubscriber(new App\Console\ErrorHandler($logger));

        $console = new Symfony\Component\Console\Application(
            'AzuraRelay Command Line Utility',
            '1.0.0'
        );
        $console->setDispatcher($eventDispatcher);

        $commandLoader = new Symfony\Component\Console\CommandLoader\ContainerCommandLoader(
            $di,
            [
                'app:nowplaying' => App\Console\Command\NowPlayingCommand::class,
                'app:setup' => App\Console\Command\SetupCommand::class,
                'app:update' => App\Console\Command\UpdateCommand::class,
            ]
        );
        $console->setCommandLoader($commandLoader);

        return $console;
    },

    // Monolog Logger
    Monolog\Logger::class => function (App\Environment $environment) {
        $logger = new Monolog\Logger($environment->getAppName());

        $loggingLevel = $environment->isProduction() ? Psr\Log\LogLevel::NOTICE : Psr\Log\LogLevel::DEBUG;

        $log_stderr = new Monolog\Handler\StreamHandler('php://stderr', $loggingLevel, true);
        $logger->pushHandler($log_stderr);

        $log_file = new Monolog\Handler\StreamHandler(
            $environment->getTempDirectory() . '/app.log',
            $loggingLevel,
            true
        );
        $logger->pushHandler($log_file);

        return $logger;
    },
    Psr\Log\LoggerInterface::class => DI\get(Monolog\Logger::class),

    AzuraCast\Api\Client::class => function(GuzzleHttp\Client $httpClient) {
        return AzuraCast\Api\Client::create(
            getenv('AZURACAST_BASE_URL'),
            getenv('AZURACAST_API_KEY'),
            $httpClient
        );
    },

    Supervisor\Supervisor::class => function() {
        $client = new fXmlRpc\Client(
            'http://127.0.0.1:9001/RPC2',
            new fXmlRpc\Transport\PsrTransport(
                new Http\Factory\Guzzle\RequestFactory,
                new GuzzleHttp\Client
            )
        );

        $supervisor = new Supervisor\Supervisor($client);

        if (!$supervisor->isConnected()) {
            throw new RuntimeException(sprintf('Could not connect to supervisord.'));
        }

        return $supervisor;
    },

    // NowPlaying Adapter factory
    NowPlaying\AdapterFactory::class => function (
        GuzzleHttp\Client $httpClient,
        Psr\Log\LoggerInterface $logger
    ) {
        return new NowPlaying\AdapterFactory(
            new Http\Factory\Guzzle\UriFactory,
            new Http\Factory\Guzzle\RequestFactory,
            $httpClient,
            $logger
        );
    },
];
