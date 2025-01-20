<?php
/**
* UpdateCkeditorText.php contains UpdateCkeditorText class 
* Defines custom ajax command for set value in CKEditor by ajax
**/ 
namespace Drupal\ubc_apsc_media_coverage_remote_article\Ajax;
use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Asset\AttachedAssets;

/**
 * Class ExtendCommand.
 */
class UpdateCkeditorText implements CommandInterface {
   /**
   * A CSS selector string.
   *
   * If the command is a response to a request from an #ajax form element then
   * this value can be NULL.
   *
   * @var string
   */
  protected $selector;

  /**
   * A jQuery method to invoke.
   *
   * @var string
   */
  protected $method;

  /**
   * An optional list of arguments to pass to the method.
   *
   * @var array
   */
  protected $arguments;

  /**
   * Constructs an InvokeCommand object.
   *
   * @param string $selector
   *   A jQuery selector.
   * @param string $method
   *   The name of a jQuery method to invoke.
   * @param array $arguments
   *   An optional array of arguments to pass to the method.
   */
  public function __construct($selector, $method, array $arguments = []) {
    $this->selector = $selector;
    $this->method = $method;
    $this->arguments = $arguments;
  }

  public function render() { 
      return [
        'command' => 'UpdateCkeditorText',
        'selector' => $this->selector,
        'args' => $this->arguments,
      ];  
    }
}

?>