<?php

namespace Opcache;

class Status {

  public $statsd  = null;
  public $result = [];

  private $supported_extra_stats = array(
      'opcache_enabled',
      'cache_full',
      'restart_pending',
      'restart_in_progress'
  );

  public $send_extra_stats = array(
      'opcache_enabled'     => false,
      'cache_full'          => false,
      'restart_pending'     => false,
      'restart_in_progress' => false,
  );

  // Takes either an array of options or a callable block
  public function __construct($options_or_block=false) {
    // Try to create a statsd handler via block or options
    if (is_callable($options_or_block)) {
      $this->statsd = $options_or_block();
    } elseif(is_array($options_or_block)) {
      $this->create_statsd_handle($options_or_block);
    }
  }

  public function configuration() {
    $raw = opcache_get_configuration();
    $this->result['config'] = $raw;
  }

  // Returns a json_encoded array of opcache status
  public function status($with_scripts = false) {

    // Guard execution if the extension is not loaded.
    if (! extension_loaded("Zend OPcache")) {
      return json_encode([]);
    }

    // Clear out data from prevous run
    $this->result['status'] = null;

    $raw = \opcache_get_status($with_scripts);

    // The scripts output has a really non-optimal format
    // for JSON, the result is a hash with the full path
    // as the key. Let's strip the key and turn it into
    // a regular array.
    if ($with_scripts == true) {

      // Make a copy of the raw scripts and then strip it from
      // the data.
      $scripts = $raw['scripts'];
      unset($raw['scripts']);

      $this->result['scripts'] = [];

      // Loop over each script and strip the key.
      foreach($scripts as $key => $val) {
        $this->result['scripts'][] = $val;
      }

      // Sort by memory consumption
      usort($this->result['scripts'], function($a, $b) {
        if ($a["memory_consumption"] == $b["memory_consumption"]) return 0;
        return ($a["memory_consumption"] < $b["memory_consumption"]) ? 1 : -1;
      });

    }

    $this->result['status'] = $raw;

    if ($this->statsd != null) {
      $this->send_to_statsd();
    }

    return json_encode($this->result);
  }

  public function send_extra_stats(array $send_extra_stats) {
      foreach ($send_extra_stats as $extra_stat_name => $send) {
          if (in_array($extra_stat_name, $this->supported_extra_stats)) {
              $this->send_extra_stats[$extra_stat_name] = $send;
          }
      }
      return true;
  }

  protected function send_to_statsd() {

    foreach ($this->send_extra_stats as $k => $send) {
        if ($send === true && in_array($k, $this->supported_extra_stats)) {
            $this->statsd->gauge($k, ($this->result["status"][$k] == true) ? 1 : 0);
        }
    }
    
    foreach($this->result["status"]["memory_usage"] as $k => $v) {
      $this->statsd->gauge($k, $v);
    }

    foreach($this->result["status"]["opcache_statistics"] as $k => $v) {
      $this->statsd->gauge($k, $v);
    }
  }

  protected function create_statsd_handle($opts) {
    // Set default statsd options
    $default = ["host"       => "127.0.0.1",
                "port"       => 8125,
                "timeout"    => null,
                "persistent" => false,
                "namespace"  => "opcache"];


    $opts = array_merge($opts, $default);

    $connection = new \Domnikl\Statsd\Connection\UdpSocket($opts["host"],
                                                        $opts["port"],
                                                        $opts["timeout"],
                                                        $opts["persistent"]);

    $this->statsd = new \Domnikl\Statsd\Client($connection, $opts["namespace"]);

  }

}
