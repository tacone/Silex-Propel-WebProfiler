<?php

namespace tacone\Silex\PropelWebProfiler;

use LogicException;
use Monolog\Logger;
use Silex\Application;
use Silex\ControllerProviderInterface;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use tacone\Silex\PropelWebProfiler\PropelLogger;
use tacone\Silex\PropelWebProfiler\PropelDataCollector;

/**
 * Silex Propel Web Profiler provider.
 *
 * @author tacone <tacone@gmail.com>
 */
class PropelWebProfilerServiceProvider implements ServiceProviderInterface, ControllerProviderInterface
{

    /**
     *
     * @var PropelDataCollector
     */
    protected $propelCollector;
    /**
     * The optional Monolog instance to delegate the logs to
     * @var LoggerInterface 
     */
    protected $logger;

    public function __construct(Logger $logger = null)
    {
        $this->logger = new PropelLogger($logger);
    }
    
    public function register(Application $app)
    {
        $this->propelCollector = $app->share(function ($app) {
                    return new PropelDataCollector( $this->logger );
                });

        $collectors = $app['data_collectors'];
        $collectors['propel'] = $this->propelCollector;

        $templates = $app['data_collector.templates'];
        $templates[] = array('propel', ( '@PropelWebProfiler/propel.html.twig'));
        $app['data_collector.templates'] = $templates;
        
        $app['twig.loader.filesystem'] = $app->share($app->extend('twig.loader.filesystem', function ($loader, $app) {
            $loader->addPath(__DIR__.'/Resources/views', 'PropelWebProfiler');

            return $loader;
        }));
    }

    public function connect(Application $app)
    {

        $controllers = $app['controllers_factory'];

        return $controllers;
    }

    public function boot(Application $app)
    {
        if (!$app['profiler'] instanceof Profiler) {
            // using RuntimeException crashes PHP?!
            throw new LogicException('You must enable the WebProfilerServiceProvider to be able to use PropelWebProfiler. See https://github.com/sensiolabs/Silex-WebProfiler');
        }
        $propelCollector= $this->propelCollector;
        $app['profiler']->add($propelCollector($app));
    }

}
