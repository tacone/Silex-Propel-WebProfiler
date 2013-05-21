<?php

namespace tacone\Silex\PropelWebProfiler;

use Exception;
use PDO;
use Propel;
use PropelConfiguration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use tacone\Silex\PropelWebProfiler\PropelLogger;

/**
 * @author tacone <tacone@gmail.com>
 * 
 * Derived from the original PropelDataCollector class written by:
 * @author William Durand <william.durand1@gmail.com>
 * 
 * Acts as DataCollector for propel and adds methods to display such info in
 * the Symfony Web Profiler
 * 
 * The reason to use this class instead of the original is to break free from
 * the ContainerAware interface, whose package has heavy dependencies.
 */
class PropelDataCollector extends DataCollector
{

    /**
     * Propel logger
     *
     * @var PropelLogger
     */
    private $logger;

    /**
     * Propel configuration
     *
     * @var PropelConfiguration
     */
    protected $propelConfiguration;

    /**
     * 
     * @param type $app
     */
    public function __construct(PropelLogger $logger)
    {
        $this->logger = $logger;
        if (!Propel::isInit())
        {
            throw new \LogicException("Propel not initialized yet, please register PropelWebProfilerServiceProvider AFTER the PropelServiceProvider");
        }
        $this->propelConfiguration = Propel::getConfiguration(PropelConfiguration::TYPE_OBJECT);
        $this->setupDebugging($logger);
    }

    protected function setupDebugging($logger)
    {
        
        $con = Propel::getConnection(null);
        $con->useDebug(true);
        $con->setLogger($logger);
        $this->propelConfiguration->setParameter('debugpdo.logging.details.time.enabled', true);
        $this->propelConfiguration->setParameter('debugpdo.logging.details.method.enabled', true);
        $this->propelConfiguration->setParameter('debugpdo.logging.details.mem.enabled', true);
    }

    /**
     * {@inheritdoc}
     */
    public function collect(Request $request, Response $response, Exception $exception = null)
    {
        $this->data = array(
            'queries' => $this->buildQueries(),
            'querycount' => $this->countQueries(),
        );
    }

    /**
     * Returns the collector name.
     *
     * @return string The collector name.
     */
    public function getName()
    {
        return 'propel';
    }

    /**
     * Returns queries.
     *
     * @return array Queries
     */
    public function getQueries()
    {
        return $this->data['queries'];
    }

    /**
     * Returns the query count.
     *
     * @return int The query count
     */
    public function getQueryCount()
    {
        return $this->data['querycount'];
    }

    /**
     * Returns the total time of queries.
     *
     * @return float The total time of queries
     */
    public function getTime()
    {
        $time = 0;
        foreach ($this->data['queries'] as $query) {
            $time += (float) $query['time'];
        }

        return $time;
    }

    /**
     * Creates an array of Build objects.
     *
     * @return array An array of Build objects
     */
    private function buildQueries()
    {
        $queries = array();

        $outerGlue = $this->propelConfiguration->getParameter('debugpdo.logging.outerglue', ' | ');
        $innerGlue = $this->propelConfiguration->getParameter('debugpdo.logging.innerglue', ': ');

        foreach ($this->logger->getQueries() as $q) {
            $parts = explode($outerGlue, $q, 4);

            $times = explode($innerGlue, $parts[0]);
            $con = explode($innerGlue, $parts[2]);
            $memories = explode($innerGlue, $parts[1]);

            $sql = trim($parts[3]);
            $con = trim($con[1]);
            $time = trim($times[1]);
            $memory = trim($memories[1]);

            $queries[] = array('connection' => $con, 'sql' => $sql, 'time' => $time, 'memory' => $memory);
        }

        return $queries;
    }

    /**
     * Count queries.
     *
     * @return int The number of queries.
     */
    private function countQueries()
    {
        return count($this->logger->getQueries());
    }

    /**
     * This method renders the global Propel configuration.
     */
    public function configurationAction($app)
    {
        $conf = Propel::getConfiguration(PropelConfiguration::TYPE_OBJECT);
//        var_export( $conf->getParameters()); die;
        return $app['twig']->render(
                        '@PropelWebProfiler/configuration.html.twig', array(
                    'propel_version' => Propel::VERSION,
                    'configuration' => $conf->getParameters(),
                    'default_connection' => Propel::getDefaultDB(),
                            
                    // Hey, you! Yes, you! What about lending me a hand and
                    // send me a pull request to help tame those beasts below?
                    'logging' => '?',      //$this->container->getParameter('propel.logging'),
                    'path' => '?',         //$this->container->getParameter('propel.path'),
                    'phing_path' => '?',   //$this->container->getParameter('propel.phing_path'),
                        )
        );
    }

    /**
     * Renders the profiler panel for the given token.
     * 
     * Rather than enforcing colors, the return value will be an HTML formatted
     * string with parts wrapped in SPAN elements of the following classes:
     * SQLKeyword, SQLComment, SQLName
     *
     * @param string  $token      The profiler token
     * @param string  $connection The connection name
     * @param integer $query the resulting HTML string
     *
     * @return Response A Response instance
     */
    public function explainAction($app, $connection, $token, $query)
    {
        $connection = null; #TODO

        $profiler = $app['profiler'];
        $profiler->disable();

        $profile = $profiler->loadProfile($token);
        $queries = $profile->getCollector('propel')->getQueries();

        if (!isset($queries[$query])) {
            return new Response('This query does not exist.');
        }

        // Open the connection
        $con = Propel::getConnection($connection);

        // Get the adapter
        $db = Propel::getDB($connection);

        try {
            $stmt = $db->doExplainPlan($con, $queries[$query]['sql']);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return new Response('<div class="error">This query cannot be explained.</div>');
        }

        return $app['twig']->render(
                        '@PropelWebProfiler//explain.html.twig', array(
                    'data' => $results,
                    'query' => $query,
                        )
        );
    }

    /**
     * Performs a tentative syntax highlighting on the given SQL string
     * @param string $sql a SQL query of part of it
     * @return string $html 
     */
    public function formatSQL($sql)
    {
        // list of keywords to prepend a newline in output
        $newlines = array(
            'FROM',
            '(((FULL|LEFT|RIGHT)? ?(OUTER|INNER)?|CROSS|NATURAL)? JOIN)',
            'VALUES',
            'WHERE',
            'ORDER BY',
            'GROUP BY',
            'HAVING',
            'LIMIT',
        );

        // list of keywords to highlight
        $keywords = array_merge($newlines, array(
            // base
            'SELECT', 'UPDATE', 'DELETE', 'INSERT', 'REPLACE',
            'SET',
            'INTO',
            'AS',
            'DISTINCT',
            // most used methods
            'COUNT',
            'AVG',
            'MIN',
            'MAX',
            // joins
            'ON', 'USING',
            // where clause
            '(IS (NOT)?)?NULL',
            '(NOT )?IN',
            '(NOT )?I?LIKE',
            'AND', 'OR', 'XOR',
            'BETWEEN',
            // order, group, limit ..
            'ASC',
            'DESC',
            'OFFSET',
                ));

        $sql = preg_replace(array(
            '/\b(' . implode('|', $newlines) . ')\b/',
            '/\b(' . implode('|', $keywords) . ')\b/',
            '/(\/\*.*\*\/)/',
            '/(`[^`.]*`)/',
            '/(([0-9a-zA-Z$_]+)\.([0-9a-zA-Z$_]+))/',
                ), array(
            '<br />\\1',
            '<span class="SQLKeyword">\\1</span>',
            '<span class="SQLComment">\\1</span>',
            '<span class="SQLName">\\1</span>',
            '<span class="SQLName">\\1</span>',
                ), $sql);

        return $sql;
    }

}