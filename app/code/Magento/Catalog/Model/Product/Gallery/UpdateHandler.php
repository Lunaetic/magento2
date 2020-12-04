<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Model\Product\Gallery;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\ResourceModel\AttributeValue;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Update handler for catalog product gallery.
 *
 * @api
 * @since 101.0.0
 */
class UpdateHandler extends Handler implements ExtensionInterface
{
    /**
     * @var AttributeValue
     */
    private $attributeValue;

    /**
     * @param MetadataPool $metadataPool
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param Gallery $resourceModel
     * @param Json $json
     * @param Config $mediaConfig
     * @param Filesystem $filesystem
     * @param Database $fileStorageDb
     * @param StoreManagerInterface|null $storeManager
     * @param AttributeValue|null $attributeValue
     * @throws FileSystemException
     */
    public function __construct(
        MetadataPool $metadataPool,
        ProductAttributeRepositoryInterface $attributeRepository,
        Gallery $resourceModel,
        Json $json,
        Config $mediaConfig,
        Filesystem $filesystem,
        Database $fileStorageDb,
        StoreManagerInterface $storeManager = null,
        ?AttributeValue $attributeValue = null
    ) {
        parent::__construct(
            $metadataPool,
            $attributeRepository,
            $resourceModel,
            $json,
            $mediaConfig,
            $filesystem,
            $fileStorageDb,
            $storeManager
        );
        $this->attributeValue = $attributeValue ?: ObjectManager::getInstance()->get(AttributeValue::class);
    }

    /**
     * Execute update handler
     *
     * @param ProductInterface $product
     * @param array $arguments
     * @return ProductInterface
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
            $value['images'] = $this->json->unserialize($value['images']); // 31121 Would like to validate that the switch here is compatible
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
            if (!empty($image['removed']) && !$this->canRemoveImage($product, $image['file'])) {
                $image['removed'] = '';
            }

            if (!empty($image['removed'])) {
                $clearImages[] = $image['file'];
                $imagesDelete[] = $image;
            } elseif (empty($image['value_id']) || !empty($image['recreate'])) {
                $newFile = $this->moveImageFromTmp($image['file']);
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
            $this->processMediaAttributes($product, $existImages, $newImages, $clearImages);
        }

        $product->setData($attrCode, $value);

        if ($product->getIsDuplicate() == true) {
            $this->duplicate($product);
            return $product;
        }

        if (!is_array($value) || !isset($value['images']) || $product->isLockedAttribute($attrCode)) {
            return $product;
        }

        $this->processDeletedImages($product, $imagesDelete);
        $this->processNewImages($product, $imagesNew);
        $this->processExistingImages($product, $imagesExist);

        $product->setData($attrCode, $value);

        return $product;
    }

    /**
     * Process deleted images
     *
     * @param ProductInterface $product
     * @param array $images
     * @return void
     * @throws FileSystemException
     * @throws LocalizedException
     * @since 101.0.0
     */
    protected function processDeletedImages(ProductInterface $product, array &$images): void
    {
        $filesToDelete = [];
        $recordsToDelete = [];
        $imagesToDelete = [];
        $imagesToNotDelete = []; // 31121 Due to refactoring, if they've made it here, this check isn't necessary; check below as well!
        foreach ($images as $image) {
            if (empty($image['removed'])) {
                $imagesToNotDelete[] = $image['file'];
            }
        }

        foreach ($images as $image) {
            if (!empty($image['removed'])) {
                if (!empty($image['value_id'])) {
                    if (preg_match('/\.\.(\\\|\/)/', $image['file'])) {
                        continue;
                    }
                    $recordsToDelete[] = $image['value_id'];
                    if (!in_array($image['file'], $imagesToNotDelete)) {
                        $imagesToDelete[] = $image['file'];
                        if ($this->canDeleteImage($image['file'])) {
                            $filesToDelete[] = ltrim($image['file'], '/');
                        }
                    }
                }
            }
        }

        $this->deleteMediaAttributeValues($product, $imagesToDelete);
        $this->resourceModel->deleteGallery($recordsToDelete);
        $this->removeDeletedImages($filesToDelete);
    }

    /**
     * Process new images
     *
     * @param ProductInterface $product
     * @param array $images
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function processNewImages(ProductInterface $product, array &$images): void
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
     * Process existing images, which may or may not need to be updated
     *
     * @param ProductInterface $product
     * @param array $images
     * @return void
     */
    protected function processExistingImages(ProductInterface $product, array &$images): void
    {
        foreach ($images as &$image) {
            $existingData = $this->resourceModel->loadDataFromTableByValueId(Gallery::GALLERY_VALUE_TABLE, [$image['value_id']], $product->getStoreId());

            if ($existingData) {
                $existingData = $existingData[0];

                if ($existingData['label'] != $image['label'] ||
                    $existingData['position'] != $image['position'] ||
                    $existingData['disabled'] != $image['disabled']) {
                    // Update per store labels, position, disabled
                    $existingData['label'] = isset($image['label']) ? $image['label'] : '';
                    $existingData['position'] = isset($image['position']) ? (int) $image['position'] : 0;
                    $existingData['disabled'] = isset($image['disabled']) ? (int) $image['disabled'] : 0;
                    $existingData['store_id'] = (int) $product->getStoreId();

                    $this->resourceModel->updateGalleryValueInStore($existingData);
                }
            }
        }
    }

    /**
     * Check if image exists and is not used by any other products
     *
     * @param string $file
     * @return bool
     * @throws LocalizedException
     */
    private function canDeleteImage(string $file): bool
    {
        $catalogPath = $this->mediaConfig->getBaseMediaPath();
        return $this->mediaDirectory->isFile($catalogPath . $file)
            && $this->resourceModel->countImageUses($file) <= 1;
    }

    /**
     * Process a new image
     *
     * @param ProductInterface $product
     * @param array $image
     * @return array
     * @throws NoSuchEntityException
     * @throws LocalizedException
     * @since 101.0.0
     */
    protected function processNewImage(ProductInterface $product, array &$image): array
    {
        $data = [];

        if (empty($image['value_id'])) {
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
        } elseif (!empty($image['recreate'])) {
            $data['value_id'] = $image['value_id'];
            $data['value'] = $image['file'];
            $data['attribute_id'] = $this->getAttribute()->getAttributeId();

            if (!empty($image['media_type'])) {
                $data['media_type'] = $image['media_type'];
            }

            $this->resourceModel->saveDataRow(Gallery::GALLERY_TABLE, $data);
        }

        return $data;
    }

    /**
     * Remove deleted images
     *
     * @param array $files
     * @return void
     * @throws FileSystemException
     * @since 101.0.0
     */
    protected function removeDeletedImages(array $files): void
    {
        $catalogPath = $this->mediaConfig->getBaseMediaPath();

        foreach ($files as $filePath) {
            $this->mediaDirectory->delete($catalogPath . '/' . $filePath);
        }
    }

    /**
     * Delete media attributes values for given images
     *
     * @param ProductInterface $product
     * @param string[] $images
     * @throws LocalizedException
     */
    private function deleteMediaAttributeValues(ProductInterface $product, array $images): void
    {
        if ($images) {
            $values = $this->attributeValue->getValues(
                ProductInterface::class,
                (int)$product->getData($this->metadata->getLinkField()),
                $this->mediaConfig->getMediaAttributeCodes()
            );
            $valuesToDelete = [];
            foreach ($values as $value) {
                if (in_array($value['value'], $images, true)) {
                    $valuesToDelete[] = $value;
                }
            }
            if ($valuesToDelete) {
                $this->attributeValue->deleteValues(
                    ProductInterface::class,
                    $valuesToDelete
                );
            }
        }
    }
}
