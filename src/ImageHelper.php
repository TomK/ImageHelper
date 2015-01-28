<?php
namespace TomK\ImageHelper;

use TomK\ImageHelper\Exceptions\InvalidFormatException;

/**
 * Support basic image manipulation using GD
 *
 * ICO support gratefully received under public domain from Jim Paris
 *  + Bugfixes and updates
 *
 */
class ImageHelper
{
  protected $_rawImage;

  public function __construct($data)
  {
    if(is_resource($data))
    {
      $this->_rawImage = $data;
    }
    else
    {
      $this->_rawImage = self::_fromIcon($data);
    }
  }

  public function resize($maxW = null, $maxH = null, $canEnlarge = false)
  {
    $src = $this->_rawImage;
    if($maxW === null && $maxH === null)
    {
      return $src;
    }
    if(imageistruecolor($src))
    {
      imageAlphaBlending($src, true);
      imageSaveAlpha($src, true);
    }
    $srcW = imagesx($src);
    $srcH = imagesy($src);

    $width = $maxW && ($canEnlarge || $maxW <= $srcW) ? $maxW : $srcW;
    $height = $maxH && ($canEnlarge || $maxH <= $srcW) ? $maxH : $srcH;

    $ratio_orig = $srcW / $srcH;
    if($width / $height > $ratio_orig)
    {
      $width = $height * $ratio_orig;
    }
    else
    {
      $height = $width / $ratio_orig;
    }
    $maxW = $maxW ? $maxW : $width;
    $maxH = $maxH ? $maxH : $height;

    $img = imagecreatetruecolor($maxW, $maxH);
    $trans_colour = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $trans_colour);

    $offsetX = ($maxW - $width) / 2;
    $offsetY = ($maxH - $height) / 2;

    imagecopyresampled(
      $img,
      $src,
      $offsetX,
      $offsetY,
      0,
      0,
      $width,
      $height,
      $srcW,
      $srcH
    );
    imagealphablending($img, true);
    imagesavealpha($img, true);

    return $img;
  }

  // Given a string with the contents of an .ICO,
  // return a GD image of the icon, or false on error.
  private static function _fromIcon($ico)
  {
    $hadAlpha = false;
    $allTransparent = true;

    // Get an image color from a string
    $_getColor = function ($str, $img) use (&$hadAlpha, &$allTransparent)
    {
      $b = ord($str[0]);
      $g = ord($str[1]);
      $r = ord($str[2]);
      if(strlen($str) > 3)
      {
        $a = 127 - (ord($str[3]) / 2);
        if($a != 0 && $a != 127)
        {
          $hadAlpha = true;
        }
      }
      else
      {
        $a = 0;
      }
      if($a != 127)
      {
        $allTransparent = false;
      }
      return imagecolorallocatealpha($img, $r, $g, $b, $a);
    };

    // Read header
    if(strlen($ico) < 6)
    {
      throw new \Exception('Input too short');
    }
    $h = unpack("vzero/vtype/vnum", $ico);

    // Must be ICO format with at least one image
    if($h["zero"] != 0 || $h["type"] != 1 || $h["num"] == 0)
    {
      // See if we can just parse it with GD directly
      // if it's not ICO format; maybe it was a mislabeled
      // PNG or something.
      $i = @imagecreatefromstring($ico);
      if($i)
      {
        imagesavealpha($i, true);
        return $i;
      }
      throw new InvalidFormatException('Invalid Image Format');
    }

    // Read directory entries to find the biggest image
    $most_pixels = 0;
    $most = null;
    for($i = 0; $i < $h["num"]; $i++)
    {
      $entry = substr($ico, 6 + 16 * $i, 16);
      if(!$entry || strlen($entry) < 16)
      {
        continue;
      }
      $e = unpack(
        "Cwidth/" .
        "Cheight/" .
        "Ccolors/" .
        "Czero/" .
        "vplanes/" .
        "vbpp/" .
        "Vsize/" .
        "Voffset/",
        $entry
      );
      if($e["width"] == 0)
      {
        $e["width"] = 256;
      }
      if($e["height"] == 0)
      {
        $e["height"] = 256;
      }
      if($e["zero"] != 0)
      {
        throw new \Exception('Nonzero reserved field');
      }
      $pixels = $e["width"] * $e["height"];
      if($pixels > $most_pixels)
      {
        $most_pixels = $pixels;
        $most = $e;
      }
    }
    if($most_pixels == 0)
    {
      throw new \Exception('No pixels');
    }

    // Extract image data
    $data = substr($ico, $most["offset"], $most["size"]);
    if(!$data || strlen($data) != $most["size"])
    {
      throw new \Exception('Bad image data');
    }

    // See if we can parse it (might be PNG format here)
    $i = @imagecreatefromstring($data);
    if($i)
    {
      imagesavealpha($i, true);
      return $i;
    }

    // Must be a BMP.  Parse it ourselves.
    $img = imagecreatetruecolor($most["width"], $most["height"]);
    imagesavealpha($img, true);
    $bg = imagecolorallocatealpha($img, 255, 0, 0, 127);
    imagefill($img, 0, 0, $bg);

    // Skip over the BITMAPCOREHEADER or BITMAPINFOHEADER;
    // we'll just assume the palette and pixel data follow
    // in the most obvious format as described by the icon
    // directory entry.
    $bitmapinfo = unpack("Vsize", $data);
    if($bitmapinfo["size"] == 40)
    {
      $info = unpack(
        "Vsize/" .
        "Vwidth/" .
        "Vheight/" .
        "vplanes/" .
        "vbpp/" .
        "Vcompress/" .
        "Vsize/" .
        "Vxres/" .
        "Vyres/" .
        "Vpalcolors/" .
        "Vimpcolors/",
        $data
      );
      if($most["bpp"] == 0)
      {
        $most["bpp"] = $info["bpp"];
      }
    }
    $data = substr($data, $bitmapinfo["size"]);

    $height = $most["height"];
    $width = $most["width"];
    $bpp = $most["bpp"];

    // For indexed images, we only support 1, 4, or 8 BPP
    switch($bpp)
    {
      case 1:
      case 4:
      case 8:
        $indexed = 1;
        break;
      case 24:
      case 32:
        $indexed = 0;
        break;
      default:
        throw new \Exception('bad BPP ' . $bpp);
    }

    $offset = 0;
    $palette = [];
    if($indexed)
    {
      for($i = 0; $i < (1 << $bpp); $i++)
      {
        $entry = substr($data, $i * 4, 4);
        $palette[$i] = $_getColor($entry, $img);
      }
      $offset = $i * 4;

      // Hack for some icons: if everything was transparent,
      // discard alpha channel.
      if($allTransparent)
      {
        for($i = 0; $i < (1 << $bpp); $i++)
        {
          $palette[$i] &= 0xffffff;
        }
      }
    }

    // Assume image data follows in bottom-up order.
    // First the "XOR" image
    if((strlen($data) - $offset) < ($bpp * $height * $width / 8))
    {
      throw new \Exception('Data is too short');
    }
    $XOR = [];
    for($y = $height - 1; $y >= 0; $y--)
    {
      $x = 0;
      while($x < $width)
      {
        if(!$indexed)
        {
          $bytes = $bpp / 8;
          $entry = substr($data, $offset, $bytes);
          $pixel = $_getColor($entry, $img);
          $XOR[$y][$x] = $pixel;
          $x++;
          $offset += $bytes;
        }
        elseif($bpp == 1)
        {
          $p = ord($data[$offset]);
          for($b = 0x80; $b > 0; $b >>= 1)
          {
            if($p & $b)
            {
              $pixel = $palette[1];
            }
            else
            {
              $pixel = $palette[0];
            }
            $XOR[$y][$x] = $pixel;
            $x++;
          }
          $offset++;
        }
        elseif($bpp == 4)
        {
          $p = ord($data[$offset]);
          $pixel1 = $palette[$p >> 4];
          $pixel2 = $palette[$p & 0x0f];
          $XOR[$y][$x] = $pixel1;
          $XOR[$y][$x + 1] = $pixel2;
          $x += 2;
          $offset++;
        }
        elseif($bpp == 8)
        {
          $pixel = $palette[ord($data[$offset])];
          $XOR[$y][$x] = $pixel;
          $x += 1;
          $offset++;
        }
        else
        {
          throw new \Exception('bad BPP');
        }
      }
      // End of row padding
      while($offset & 3)
      {
        $offset++;
      }
    }

    // Now the "AND" image, which is 1 bit per pixel.  Ignore
    // if some of our image data already had alpha values,
    // or if there isn't enough data left.
    if($hadAlpha || ((strlen($data) - $offset) < ($height * $width / 8)))
    {
      // Just return what we've got
      for($y = 0; $y < $height; $y++)
      {
        for($x = 0; $x < $width; $x++)
        {
          imagesetpixel($img, $x, $y, $XOR[$y][$x]);
        }
      }
      return $img;
    }

    // Mask what we have with the "AND" image
    for($y = $height - 1; $y >= 0; $y--)
    {
      $x = 0;
      while($x < $width)
      {
        for($b = 0x80;
            $b > 0 && $x < $width; $b >>= 1)
        {
          if(!(ord($data[$offset]) & $b))
          {
            imagesetpixel($img, $x, $y, $XOR[$y][$x]);
          }
          $x++;
        }
        $offset++;
      }

      // End of row padding
      while($offset & 3)
      {
        $offset++;
      }
    }
    return $img;
  }
}
