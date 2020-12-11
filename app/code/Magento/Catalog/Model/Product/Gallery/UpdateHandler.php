<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Model\Product\Gallery;

use Magento\Catalog\Helper\Product\Gallery as GalleryHelper;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\ResourceModel\AttributeValue;
use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;

/**
 */
class UpdateHandler implements ExtensionInterface
{
    /**
     * @var Gallery
     */
    protected $resourceModel;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var Config
     */
    protected $mediaConfig;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var AttributeValue
     */
    protected $attributeValue;

    /**
     * @var GalleryHelper
     */
    protected $galleryHelper;

    /**
     * @param Gallery $resourceModel
     * @param Json $json
     * @param Config $mediaConfig
     * @param Filesystem $filesystem
     * @param AttributeValue $attributeValue
     * @param GalleryHelper $galleryHelper
     */
    public function __construct(
        Gallery $resourceModel,
        Json $json,
        Config $mediaConfig,
        Filesystem $filesystem,
        AttributeValue $attributeValue,
        GalleryHelper $galleryHelper
    ) {
        $this->resourceModel = $resourceModel;
        $this->json = $json;
        $this->mediaConfig = $mediaConfig;
        $this->filesystem = $filesystem;
        $this->attributeValue = $attributeValue;
        $this->galleryHelper = $galleryHelper;
    }

    /**
     * @param object $product
     * @param array $arguments
     * @return bool|object
     * @throws FileSystemException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute($product, $arguments = [])
    {
        $attrCode = $this->galleryHelper->getAttribute()->getAttributeCode();

        $value = $product->getData($attrCode);

        if (!is_array($value) || !isset($value['images'])) {
            return $product;
        }

        if (!is_array($value['images']) && strlen($value['images']) > 0) {
            $value['images'] = $this->json->unserialize($value['images']);
        }

        if (!is_array($value['images'])) {
            $value['images'] = [];
        }

        $clearImages = [];
        $newImages = [];
        $existImages = [];

        $imagesDelete = [];
        $imagesExist = [];
        $imagesNew = [];

        foreach ($value['images'] as &$image) {
            if (!empty($image['removed']) && !$this->galleryHelper->canRemoveImage($product, $image['file'])) {
                $image['removed'] = '';
            }

            if (!empty($image['removed'])) {
                $clearImages[] = $image['file'];
                $imagesDelete[] = $image;
            } elseif (empty($image['value_id']) || !empty($image['recreate'])) {
                $newFile = $this->galleryHelper->moveImageFromTmp($image['file']);
                $image['new_file'] = $newFile;
                $newImages[$image['file']] = $image;
                $image['file'] = $newFile;
                $imagesNew[] = $image;
            } else {
                $existImages[$image['file']] = $image;
                $imagesExist[] = $image;
            }
        }

        if (!empty($value['images'])) {
            $this->galleryHelper->processMediaAttributes($product, $existImages, $newImages, $clearImages);
        }

        $product->setData($attrCode, $value);

        if ($product->getIsDuplicate() == true) {
            $this->galleryHelper->duplicate($product);
            return $product;
        }

        if (!is_array($value) || !isset($value['images']) || $product->isLockedAttribute($attrCode)) {
            return $product;
        }

        $this->galleryHelper->processDeletedImages($product, $imagesDelete);
        $this->galleryHelper->processNewImages($product, $imagesNew);
        $this->galleryHelper->processExistingImages($product, $imagesExist);

        $product->setData($attrCode, $value);

        return $product;
    }
}
