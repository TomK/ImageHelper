<?php
namespace Tests;

use TomK\ImageHelper\ImageHelper;

class ImageHelperTest extends \PHPUnit_Framework_TestCase
{
  /**
   * @expectedException \TomK\ImageHelper\Exceptions\InvalidFormatException
   */
  public function testInvalid()
  {
    new ImageHelper('invalid image data');
  }

  public function testIcoFormat()
  {
    $image = new ImageHelper(
      file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'favicon.ico')
    );

    $this->assertInstanceOf('\TomK\ImageHelper\ImageHelper', $image);
    $this->assertInternalType('resource', $image->resize());
  }
}
