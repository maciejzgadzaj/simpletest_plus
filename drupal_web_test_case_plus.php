<?php

class DrupalWebTestCasePlus extends DrupalWebTestCase {

  /**
   * All results of all tests.
   *
   * @var Array
   */
  public $allResults = array();

  /**
   * {@inheritdoc}
   */
  public function run(array $methods = array()) {
    // Initialize verbose debugging.
    $class = get_class($this);
    simpletest_plus_verbose(NULL, variable_get('file_public_path', conf_path() . '/files'), str_replace('\\', '_', $class), $this->testId);

    // HTTP auth settings (<username>:<password>) for the simpletest browser
    // when sending requests to the test site.
    $this->httpauth_method = variable_get('simpletest_httpauth_method', CURLAUTH_BASIC);
    $username = variable_get('simpletest_httpauth_username', NULL);
    $password = variable_get('simpletest_httpauth_password', NULL);
    if ($username && $password) {
      $this->httpauth_credentials = $username . ':' . $password;
    }

    set_error_handler(array($this, 'errorHandler'));
    // Iterate through all the methods in this class, unless a specific list of
    // methods to run was passed.
    $class_methods = get_class_methods($class);
    if ($methods) {
      $class_methods = array_intersect($class_methods, $methods);
    }
    foreach ($class_methods as $method) {
      // If the current method starts with "test", run it - it's a test.
      if (strtolower(substr($method, 0, 4)) == 'test') {
        $this->methodName = $method;

        // Insert a fail record. This will be deleted on completion to ensure
        // that testing completed.
        $method_info = new ReflectionMethod($class, $method);
        $caller = array(
          'file' => $method_info->getFileName(),
          'line' => $method_info->getStartLine(),
          'function' => $class . '->' . $method . '()',
        );
        $completion_check_id = DrupalTestCase::insertAssert($this->testId, $class, FALSE, t('The test did not complete due to a fatal error.'), 'Completion check', $caller);
        $this->setUp();
        if ($this->setup) {
          try {
            $this->$method();
            // Finish up.
          }
          catch (Exception $e) {
            $this->exceptionHandler($e);
          }
          $this->tearDown();
        }
        else {
          $this->fail(t("The test cannot be executed because it has not been set up properly."));
        }
        // Remove the completion check record.
        DrupalTestCase::deleteAssert($completion_check_id);
      }
    }
    $this->writeVerbose();

    // Clear out the error messages and restore error handler.
    drupal_get_messages();
    restore_error_handler();
  }

  /**
   * {@inheritdoc}
   */
  protected function verbose($message) {
    if ($id = simpletest_plus_verbose($message)) {
      $index_directory = $this->originalFileDirectory . '/simpletest/' . $this->testId;
      file_prepare_directory($index_directory, FILE_CREATE_DIRECTORY);
      $directory = $this->originalFileDirectory . '/simpletest/' . $this->testId . '/verbose';
      file_prepare_directory($directory, FILE_CREATE_DIRECTORY);

      $new = !file_exists($index_directory . '/verbose.html');
      $index_file = fopen($index_directory . '/verbose.html', 'a+');
      if ($new) {
        fputs($index_file, '<html><head><title>Verbose</title></head><body>');
      }
      if ($id == 1) {
        if (!$new) {
          fputs($index_file, '</table>');
        }
        fputs($index_file, '<h1>' . $this->classNameSafe() . '</h1><table width="100%" cellpadding="10"><tr bgcolor="#ccc"><td>Id</td><td>Function</td><td>Time</td><td>Message</td></tr>');
      }

      $index_message = strtr('<tr bgcolor="@bgcolor"><td><a href="@url">@id</td><td>@function</td><td>@timestamp</td><td>@message</td></tr>', array(
        '@bgcolor' => $id % 2 == 0 ? '#f0f0f0' : '',
        '@url' => "verbose/{$this->classNameSafe()}-$id.html",
        '@id' => $id,
        '@function' => "{$this->classNameSafe()}->{$this->methodName}()",
        '@timestamp' => date('Y-m-d H:i:s'),
        '@message' => substr(strip_tags(str_replace('<hr />', ' - ', $message)), 0, 100),
      ));
      fputs($index_file, $index_message);
      fclose($index_file);

      $url = file_create_url($this->originalFileDirectory . "/simpletest/{$this->testId}/verbose/{$this->classNameSafe()}-$id.html");
      $this->error(l(t('Verbose message'), $url, array('attributes' => array('target' => '_blank'))), 'User notice');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown() {
    $this->writeWatchdog();
    $this->copyDrupalDebug();
    $this->copyCronLog();

    parent::tearDown();
  }

  /**
   * Exports watchdog messages into HTML files in simpletest output directory.
   *
   * @see tearDown()
   */
  protected function writeWatchdog() {
    $index_directory = $this->originalFileDirectory . '/simpletest/' . $this->testId;
    file_prepare_directory($index_directory, FILE_CREATE_DIRECTORY);
    $directory = $this->originalFileDirectory . '/simpletest/' . $this->testId . '/watchdog';
    file_prepare_directory($directory, FILE_CREATE_DIRECTORY);

    $new = !file_exists($index_directory . '/watchdog.html');
    $index_file = fopen($index_directory . '/watchdog.html', 'a+');
    if ($new) {
      fputs($index_file, '<html><head><title>Watchdog</title></head><body>');
    }

    fputs($index_file, strtr('<h1>@function</h1><table width="100%" cellpadding="10"><tr bgcolor="#ccc"><td>Id</td><td>Severity</td><td>Type</td><td>Function</td><td>Time</td><td>Message</td></tr>', array(
      '@function' => "{$this->classNameSafe()}: {$this->methodName}",
    )));

    $results = db_query("SELECT * FROM {watchdog}");
    $severity_levels = watchdog_severity_levels();
    foreach ($results as $result) {
      $variables = !empty($result->variables) && unserialize($result->variables) != NULL ? unserialize($result->variables) : array();
      $message = strtr('<hr />@function<hr/>WID : @wid<hr/>Type : @type<hr/>Message : @message<hr/>Severity : @severity<hr/>Link : @link<hr/>Location : @location<hr/>Referer : @referer<hr/>Hostname : @hostname<hr/>Timestamp : @timestamp', array(
        '@function' => get_class($this) . '->' . $this->methodName,
        '@wid' => $result->wid,
        '@type' => $result->type,
        '@message' => strtr($result->message, $variables),
        '@severity' => $severity_levels[$result->severity],
        '@link' => $result->link,
        '@location' => $result->location,
        '@referer' => $result->referer,
        '@hostname' => $result->hostname,
        '@timestamp' => date('Y-m-d H:i:s', $result->timestamp),
      ));
      file_put_contents("$directory/{$this->classNameSafe()}-{$this->methodName}-{$result->wid}.html", $message);

      $message = strtr('<tr bgcolor="@bgcolor"><td><a href="@url">@wid</a></td><td>@severity</td><td>@type</td><td>@function</td><td>@timestamp</td><td>@message</td></tr>' . PHP_EOL, array(
        '@bgcolor' => $result->severity <= 4 ? '#ffdddd' : ($result->wid % 2 == 0 ? '#f0f0f0' : ''),
        '@url' => "watchdog/{$this->classNameSafe()}-{$this->methodName}-{$result->wid}.html",
        '@wid' => $result->wid,
        '@severity' => $severity_levels[$result->severity],
        '@type' => $result->type,
        '@function' => get_class($this) . '->' . $this->methodName,
        '@timestamp' => date('Y-m-d H:i:s', $result->timestamp),
        '@message' => substr(strip_tags(strtr($result->message, $variables)), 0, 100),
      ));
      fputs($index_file, $message);
    }

    fputs($index_file, '</table>');
    fclose($index_file);
  }

  /**
   * Copies drupal_debug.txt file to simpletest output directory.
   *
   * @see tearDown()
   */
  protected function copyDrupalDebug() {
    if (file_exists(file_directory_temp() . '/drupal_debug.txt')) {
      file_unmanaged_copy(file_directory_temp() . '/drupal_debug.txt', $this->originalFileDirectory . '/simpletest/' . $this->testId . '/');
    }
  }

  /**
   * Copies cron.log file to simpletest output directory.
   *
   * @see tearDown()
   */
  protected function copyCronLog() {
    if (file_exists(file_directory_temp() . '/curl.log')) {
      file_unmanaged_copy(file_directory_temp() . '/curl.log', $this->originalFileDirectory . '/simpletest/' . $this->testId . '/');
    }
  }

  /**
   * Exports verbose messages into a HTML file in simpletest output directory.
   *
   * @see tearDown()
   */
  protected function writeVerbose() {
    $directory = $this->originalFileDirectory . '/simpletest/' . $this->testId;
    file_prepare_directory($directory, FILE_CREATE_DIRECTORY);

    $new = !file_exists($directory . '/assertions.html');
    $full_file = fopen($directory . '/assertions.html', 'a+');
    $fail_file = fopen($directory . '/failed-assertions.html', 'a+');

    if ($new) {
      $header = '<html><head><title>Assertion messages</title></head><body>';
      fputs($full_file, $header);
      fputs($fail_file, $header);
    }

    $header = strtr('<h1>' . get_class($this) . '</h1><p><b>#pass passes, #fail fails, #exception exceptions, and #debug debug messages</b></p><table width="100%" cellpadding="10"><tr bgcolor="#ccc"><td>Id</td><td>Status</td><td>Group</td><td>Function</td><td>Line</td><td>Message</td></tr>', $this->results);
    fputs($full_file, $header);
    fputs($fail_file, $header);

    $count = array(
      'total' => 0,
      'pass' => 0,
      'fail' => 0,
      'exception' => 0,
    );
    $results = db_query("SELECT * FROM {simpletest} WHERE test_id = :test_id AND test_class = :test_class ORDER BY test_class, message_id", array(':test_id' => $this->testId, ':test_class' => get_class($this)));
    foreach ($results as $result) {
      // Array of keys of $results_map not-so-global variable
      // as defined in simpletest_script_reporter_init() in run-tests.sh.
      if (in_array($result->status, array_keys($count))) {
        $message = '<tr id="id@id" bgcolor="@bgcolor"><td>@id</td><td>@status</td><td>@message_group</td><td>@function</td><td>@line</td><td>@message</td></tr>';
        $message_variables = array(
          '@bgcolor' => in_array($result->status, array('fail', 'exception')) ? ($count['total']++ % 2 == 0 ? '#ffdddd' : '#ffcccc') : ($count['total']++ % 2 == 0 ? '#ddffdd' : 'ccffcc'),
          '@id' => $result->message_id,
          '@status' => $result->status,
          '@message_group' => $result->message_group,
          '@function' => $result->function,
          '@line' => $result->line,
          '@message' => $result->message,
        );
        fputs($full_file, strtr($message, $message_variables));
        if ($result->status == 'fail') {
          $message_variables['@id'] = "<a href='assertions.html#id{$result->message_id}'>{$result->message_id}</a>";
          fputs($fail_file, strtr($message, $message_variables));
        }
        $count[$result->status]++;
      }
    }

    fputs($full_file, '</table>');
    fputs($fail_file, '</table>');
    fclose($full_file);
    fclose($fail_file);
  }

  protected function classNameSafe() {
    return str_replace('\\', '_', get_class($this));
  }

}

/**
 * Logs verbose message in a text file.
 *
 * If verbose mode is enabled then page requests will be dumped to a file and
 * presented on the test result screen. The messages will be placed in a file
 * located in the simpletest directory in the original file system.
 *
 * @param $message
 *   The verbose message to be stored.
 * @param $original_file_directory
 *   The original file directory, before it was changed for testing purposes.
 * @param $test_class
 *   The active test case class.
 *
 * @return
 *   The ID of the message to be placed in related assertion messages.
 *
 * @see DrupalTestCase->originalFileDirectory
 * @see DrupalWebTestCase->verbose()
 */
function simpletest_plus_verbose($message, $original_file_directory = NULL, $test_class = NULL, $current_test_id = NULL) {
  static $file_directory = NULL, $class = NULL, $id = 1, $verbose = NULL, $test_id = NULL;

  // Will pass first time during setup phase, and when verbose is TRUE.
  if (!isset($original_file_directory) && !$verbose) {
    return FALSE;
  }

  if ($message && $file_directory) {
    $message = '<hr />ID #' . $id . ' (<a href="' . $class . '-' . ($id - 1) . '.html">Previous</a> | <a href="' . $class . '-' . ($id + 1) . '.html">Next</a>)<hr />' . $message;
    file_put_contents($file_directory . "/simpletest/$test_id/verbose/$class-$id.html", $message, FILE_APPEND);
    return $id++;
  }

  if ($original_file_directory) {
    $file_directory = $original_file_directory;
    $class = $test_class;
    $test_id = $current_test_id;
    $verbose = variable_get('simpletest_verbose', TRUE);

    $simpletest_directory = $file_directory . '/simpletest';
    $writable = file_prepare_directory($simpletest_directory, FILE_CREATE_DIRECTORY);
    if ($writable && !file_exists($simpletest_directory . '/.htaccess')) {
      file_put_contents($simpletest_directory . '/.htaccess', "<IfModule mod_expires.c>\nExpiresActive Off\n</IfModule>\n");
    }

    $directory = $simpletest_directory . '/' . $test_id . '/verbose';
    return file_prepare_directory($directory, FILE_CREATE_DIRECTORY);
  }
  return FALSE;
}
