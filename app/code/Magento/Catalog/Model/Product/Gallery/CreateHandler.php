<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Model\Product\Gallery;

use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Catalog\Helper\Product\Gallery as GalleryHelper;
use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;

/**
 */
class CreateHandler implements ExtensionInterface
{
    /**
     * @var Gallery
     */
    protected $resourceModel;

    /**
     * @var GalleryHelper
     */
    protected $galleryHelper;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @param Gallery $resourceModel
     * @param GalleryHelper $galleryHelper
     * @param Json $json
     */
    public function __construct(
        Gallery $resourceModel,
        GalleryHelper $galleryHelper,
        Json $json
    ) {
        $this->resourceModel = $resourceModel;
        $this->galleryHelper = $galleryHelper;
        $this->json = $json;
    }

    /**
     * @param object $product
     * @param array $arguments
     * @return bool|object
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

        $newImages = [];

        $imagesNew = [];

        if ($product->getIsDuplicate() != true) {
            foreach ($value['images'] as &$image) {
                if (empty($image['value_id']) || !empty($image['recreate'])) {
                    $newFile = $this->galleryHelper->moveImageFromTmp($image['file']);
                    $image['new_file'] = $newFile;
                    $newImages[$image['file']] = $image;
                    $image['file'] = $newFile;
                    $imagesNew[] = $image;
                }
            }
        } else {
            // For duplicating we need to copy the original images
            $duplicate = [];
            foreach ($value['images'] as &$image) {
                if (!empty($image['removed']) && !$this->galleryHelper->canRemoveImage($product, $image['file'])) {
                    $image['removed'] = '';
                }

                if (empty($image['value_id']) || !empty($image['removed'])) {
                    continue;
                }

                $duplicate[$image['value_id']] = $this->galleryHelper->copyImage($image['file']);
                $image['new_file'] = $duplicate[$image['value_id']];
                $newImages[$image['file']] = $image;
                $imagesNew[] = $image;
            }

            $value['duplicate'] = $duplicate;
        }

        if (!empty($value['images'])) {
            $this->galleryHelper->processMediaAttributes($product, [], $newImages, []);
        }

        $product->setData($attrCode, $value);

        if ($product->getIsDuplicate() == true) {
            $this->galleryHelper->duplicate($product);
            return $product;
        }

        if (!is_array($value) || !isset($value['images']) || $product->isLockedAttribute($attrCode)) {
            return $product;
        }

        $this->galleryHelper->processNewImages($product, $imagesNew);

        $product->setData($attrCode, $value);

        return $product;
    }
}
