<?php

namespace tacone\Silex\PropelWebProfiler;

use BasicLogger;
use Propel;

class PropelLogger implements BasicLogger
{

    protected $logger;
    protected $queries = array();

    function __construct($logger = null)
    {
        $this->logger = $logger;
    }

    public function emergency($m)
    {
        $this->log($m, Propel::LOG_EMERG);
    }

    public function alert($m)
    {
        $this->log($m, Propel::LOG_ALERT);
    }

    public function crit($m)
    {
        $this->log($m, Propel::LOG_CRIT);
    }

    public function err($m)
    {
        $this->log($m, Propel::LOG_ERR);
    }

    public function warning($m)
    {
        $this->log($m, Propel::LOG_WARNING);
    }

    public function notice($m)
    {
        $this->log($m, Propel::LOG_NOTICE);
    }

    public function info($m)
    {
        $this->log($m, Propel::LOG_INFO);
    }

    public function debug($m)
    {
        $this->log($m, Propel::LOG_DEBUG);
    }

    public function log($message, $priority = null)
    {
        if ( $this->logger !== null )
        {
            $this->logger->log(400, $message, array());
        }

        $this->queries[] = $message;
    }

    public function getQueries()
    {
        return $this->queries;
    }

}
