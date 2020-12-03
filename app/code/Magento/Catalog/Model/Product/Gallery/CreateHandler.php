<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Model\Product\Gallery;

use Magento\Catalog\Model\Product;
use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Create handler for catalog product gallery
 *
 * @api
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @since 101.0.0
 */
class CreateHandler extends Handler implements ExtensionInterface
{
    /**
     * Execute create handler
     *
     * @param Product $product
     * @param array $arguments
     * @return object
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @since 101.0.0
     */
    public function execute($product, $arguments = [])
    {
        $attrCode = $this->getAttribute()->getAttributeCode();

        $value = $product->getData($attrCode);

        if (!is_array($value) || !isset($value['images'])) {
            return $product;
        }

        if (!is_array($value['images']) && strlen($value['images']) > 0) {
            $value['images'] = $this->jsonHelper->jsonDecode($value['images']);
        }

        if (!is_array($value['images'])) {
            $value['images'] = [];
        }

        $newImages = [];

        $imagesNew = [];

        if ($product->getIsDuplicate() != true) {
            foreach ($value['images'] as &$image) {
                if (empty($image['value_id']) || !empty($image['recreate'])) {
                    $newFile = $this->moveImageFromTmp($image['file']);
                    $image['new_file'] = $newFile;
                    $newImages[$image['file']] = $image;
                    $image['file'] = $newFile;
                    $imagesNew[] = $image;
                }
            }
        } else {
            // For duplicating we need copy original images.
            $duplicate = [];
            foreach ($value['images'] as &$image) {
                if (!empty($image['removed']) && !$this->canRemoveImage($product, $image['file'])) {
                    $image['removed'] = '';
                }

                if (empty($image['value_id']) || !empty($image['removed'])) {
                    continue;
                }

                $duplicate[$image['value_id']] = $this->copyImage($image['file']);
                $image['new_file'] = $duplicate[$image['value_id']];
                $newImages[$image['file']] = $image;
                $imagesNew[] = $image;
            }

            $value['duplicate'] = $duplicate;
        }

        if (!empty($value['images'])) {
            $this->processMediaAttributes($product, [], $newImages, []);
        }

        $product->setData($attrCode, $value);

        if ($product->getIsDuplicate() == true) {
            $this->duplicate($product);
            return $product;
        }

        if (!is_array($value) || !isset($value['images']) || $product->isLockedAttribute($attrCode)) {
            return $product;
        }

        $this->processNewImages($product, $imagesNew);

        $product->setData($attrCode, $value);

        return $product;
    }

    /**
     * Process images
     *
     * @param Product $product
     * @param array $images
     * @return void
     * @throws NoSuchEntityException
     * @since 101.0.0 // 31121
     */
    protected function processNewImages($product, array &$images)
    {
        foreach ($images as &$image) {
            $data = $this->processNewImage($product, $image);

            // Add per store labels, position, disabled
            $data['value_id'] = $image['value_id'];
            $data['label'] = isset($image['label']) ? $image['label'] : '';
            $data['position'] = isset($image['position']) ? (int)$image['position'] : 0;
            $data['disabled'] = isset($image['disabled']) ? (int)$image['disabled'] : 0;
            $data['store_id'] = (int)$product->getStoreId();

            $data[$this->metadata->getLinkField()] = (int)$product->getData($this->metadata->getLinkField());

            $this->resourceModel->insertGalleryValueInStore($data);
        }
    }

    /**
     * Processes image as new.
     *
     * @param Product $product
     * @param array $image
     * @return array
     * @throws NoSuchEntityException
     * @since 101.0.0
     */
    protected function processNewImage($product, array &$image)
    {
        $data = [];

        $data['value'] = $image['file'];
        $data['attribute_id'] = $this->getAttribute()->getAttributeId();

        if (!empty($image['media_type'])) {
            $data['media_type'] = $image['media_type'];
        }

        $image['value_id'] = $this->resourceModel->insertGallery($data);

        $this->resourceModel->bindValueToEntity(
            $image['value_id'],
            $product->getData($this->metadata->getLinkField())
        );

        return $data;
    }
}
