<?php

namespace h0rseduck\file;

use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\ManipulatorInterface;
use Yii;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\db\BaseActiveRecord;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\imagine\Image;

/**
 * UploadImageBehavior automatically uploads image, creates thumbnails and fills
 * the specified attribute with a value of the name of the uploaded image.
 *
 * To use UploadImageBehavior, insert the following code to your ActiveRecord class:
 *
 * ```php
 * use h0rseduck\file\UploadImageBehavior;
 *
 * function behaviors()
 * {
 *     return [
 *         [
 *             'class' => UploadImageBehavior::class,
 *             'attribute' => 'file',
 *             'scenarios' => ['insert', 'update'],
 *             'placeholder' => '@app/modules/user/assets/images/userpic.jpg',
 *             'path' => '@webroot/upload/{id}/images',
 *             'url' => '@web/upload/{id}/images',
 *             'thumbPath' => '@webroot/upload/{id}/images/thumb',
 *             'thumbUrl' => '@web/upload/{id}/images/thumb',
 *             'autorotate' => true,
 *             'thumbs' => [
 *                   'thumb' => ['width' => 400, 'quality' => 90],
 *                   'preview' => ['width' => 200, 'height' => 200],
 *              ],
 *         ],
 *     ];
 * }
 * ```
 *
 * @author Alexander Mohorev <dev.mohorev@gmail.com>
 * @author Alexey Samoylov <alexey.samoylov@gmail.com>
 * @author H0rse Duck <thenewsit@gmail.com>
 */
class UploadImageBehavior extends UploadBehavior
{
    /**
     * @var string
     */
    public $placeholder;

    /**
     * @var boolean
     */
    public $createThumbsOnSave = true;

    /**
     * @var boolean
     */
    public $createThumbsOnRequest = false;

    /**
     * Rotates an image automatically based on EXIF information.
     * @var boolean
     */
    public $autorotate = false;

    /**
     * Whether delete original uploaded image after thumbs generating.
     * Defaults to FALSE
     * @var boolean
     */
    public $deleteOriginalFile = false;

    /**
     * @var array the thumbnail profiles
     * - `width`
     * - `height`
     * - `quality`
     */
    public $thumbs = [
        'thumb' => ['width' => 200, 'height' => 200, 'quality' => 90],
    ];

    /**
     * @var string|null
     */
    public $thumbPath;

    /**
     * @var string|null
     */
    public $thumbUrl;

    /**
     * @var ImageInterface
     */
    private $originalImage;

    /**
     * @inheritdoc
     * @throws NotSupportedException
     * @throws InvalidConfigException
     */
    public function init()
    {
        if (!class_exists(Image::class)) {
            throw new NotSupportedException("Yii2-imagine extension is required to use the UploadImageBehavior");
        }

        parent::init();

        if ($this->createThumbsOnSave || $this->createThumbsOnRequest) {
            if ($this->thumbPath === null) {
                $this->thumbPath = $this->path;
            }
            if ($this->thumbUrl === null) {
                $this->thumbUrl = $this->url;
            }
        }
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     * @throws \yii\base\Exception
     */
    protected function afterUpload()
    {
        parent::afterUpload();
        $path = $this->getPathOriginalImage();
        if ($path) {
            $this->originalImage = Image::getImagine()->open($path);
            if ($this->autorotate) {
                Image::autorotate($this->originalImage)->save($path);
            }
            if ($this->createThumbsOnSave) {
                $this->createThumbs($path);
            }
        }
    }

    /**
     * @param $path
     * @throws InvalidConfigException
     * @throws \yii\base\Exception
     */
    protected function createThumbs($path)
    {
        foreach ($this->thumbs as $profile => $config) {
            $thumbPath = $this->getThumbUploadPath($this->attribute, $profile);
            if ($thumbPath !== null) {
                if (!FileHelper::createDirectory(dirname($thumbPath))) {
                    throw new InvalidArgumentException(
                        "Directory specified in 'thumbPath' attribute doesn't exist or cannot be created."
                    );
                }
                if (!is_file($thumbPath)) {
                    $this->generateImageThumb($config, $path, $thumbPath);
                }
            }
        }

        if ($this->deleteOriginalFile) {
            parent::delete($this->attribute);
        }
    }

    /**
     * @param string $attribute
     * @param string $profile
     * @param boolean $old
     * @return string
     */
    public function getThumbUploadPath($attribute, $profile = 'thumb', $old = false)
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;
        $path = $this->resolvePath($this->thumbPath);
        $attribute = ($old === true) ? $model->getOldAttribute($attribute) : $model->$attribute;
        $filename = $this->getThumbFileName($attribute, $profile);

        return $filename ? Yii::getAlias($path . '/' . $filename) : null;
    }

    /**
     * @param string $attribute
     * @param string $profile
     * @return string|null
     * @throws InvalidConfigException
     * @throws \yii\base\Exception
     */
    public function getThumbUploadUrl($attribute, $profile = 'thumb')
    {
        /** @var BaseActiveRecord $model */
        $model = $this->owner;
        $path = $this->getPathOriginalImage();
        if ($path && $this->createThumbsOnRequest) {
            $this->createThumbs($path);
        }

        if (is_file($this->getThumbUploadPath($attribute, $profile))) {
            $url = $this->resolvePath($this->thumbUrl);
            $fileName = $model->getOldAttribute($attribute);
            $thumbName = $this->getThumbFileName($fileName, $profile);

            return Yii::getAlias($url . '/' . $thumbName);
        } elseif ($this->placeholder) {
            return $this->getPlaceholderUrl($profile);
        } else {
            return null;
        }
    }

    /**
     * @param $profile
     * @return string
     * @throws InvalidConfigException
     */
    protected function getPlaceholderUrl($profile)
    {
        list ($path, $url) = Yii::$app->assetManager->publish($this->placeholder);
        $filename = basename($path);
        $thumb = $this->getThumbFileName($filename, $profile);
        $thumbPath = dirname($path) . DIRECTORY_SEPARATOR . $thumb;
        $thumbUrl = dirname($url) . '/' . $thumb;

        if (!is_file($thumbPath)) {
            $this->generateImageThumb($this->thumbs[$profile], $path, $thumbPath);
        }

        return $thumbUrl;
    }

    /**
     * @inheritdoc
     */
    protected function delete($attribute, $old = false)
    {
        parent::delete($attribute, $old);

        $profiles = array_keys($this->thumbs);
        foreach ($profiles as $profile) {
            $path = $this->getThumbUploadPath($attribute, $profile, $old);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param $filename
     * @param string $profile
     * @return string
     */
    protected function getThumbFileName($filename, $profile = 'thumb')
    {
        return $profile . '-' . $filename;
    }

    /**
     * @param array $config
     * @param string $path
     * @param string $thumbPath
     * @throws InvalidConfigException
     */
    protected function generateImageThumb($config, $path, $thumbPath)
    {
        $width = $this->getConfigValue($config, 'width');
        $height = $this->getConfigValue($config, 'height');

        if ($height < 1 && $width < 1) {
            throw new InvalidConfigException(sprintf(
                'Length of either side of thumb cannot be 0 or negative, current size ' .
                'is %sx%s', $width, $height
            ));
        }

        $quality = $this->getConfigValue($config, 'quality', 100);
        $mode = $this->getConfigValue($config, 'mode', ManipulatorInterface::THUMBNAIL_INSET);
        $bg_color = $this->getConfigValue($config, 'bg_color', 'FFF');

        if (!$width || !$height) {
            $ratio = $this->originalImage->getSize()->getWidth() / $this->originalImage->getSize()->getHeight();
            if ($width) {
                $height = ceil($width / $ratio);
            } else {
                $width = ceil($height * $ratio);
            }
        }

        $processor = ArrayHelper::getValue($config, 'processor');
        if (!$processor || !is_callable($processor)) {
            $processor = function (ImageInterface $thumb, $width, $height, $mode) {
                return Image::thumbnail($thumb, $width, $height, $mode);
            };
        }

        // Fix error "PHP GD Allowed memory size exhausted".
        ini_set('memory_limit', '512M');
        Image::$thumbnailBackgroundColor = $bg_color;

        $thumb = clone $this->originalImage;
        $thumb = call_user_func($processor, $thumb, $width, $height, $mode);
        $thumb->save($thumbPath, ['quality' => $quality]);
    }

    /**
     * @param array $config
     * @param string $attribute
     * @param mixed|null $default
     * @return mixed
     */
    private function getConfigValue($config, $attribute, $default = null)
    {
        $value = ArrayHelper::getValue($config, $attribute, $default);
        if ($value instanceof \Closure) {
            $value = call_user_func($value, $this->owner);
        }
        return $value;
    }

    /**
     * @return bool|string
     */
    private function getPathOriginalImage()
    {
        $path = $this->getUploadPath($this->attribute);
        if (!$path || !is_file($path)) {
            return false;
        }
        return $path;
    }
}
