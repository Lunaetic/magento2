<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Helper\Product;

use Exception;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Eav\Model\ResourceModel\AttributeValue;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\EntityManager\EntityMetadata;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\MediaStorage\Model\File\Uploader as FileUploader;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\ResourceModel\Product\Gallery as GalleryResource;

/**
 */
class Gallery
{
    protected const TMP_SUFFIX = '.tmp';

    /**
     * @var ProductAttributeInterface
     */
    protected $attribute;

    /**
     * @var ProductAttributeRepositoryInterface
     */
    protected $attributeRepository;

    /**
     * @var AttributeValue
     */
    protected $attributeValue;

    /**
     * @var Database
     */
    protected $fileStorageDb;

    /**
     * @var array
     */
    protected $imagesGallery;

    /**
     * @var array
     */
    protected $mediaAttributeCodes;

    /**
     * @var Config
     */
    protected $mediaConfig;

    /**
     * @var WriteInterface
     */
    protected $mediaDirectory;

    /**
     * @var EntityMetadata
     */
    protected $metadata;

    /**
     * @var GalleryResource
     */
    protected $resourceModel;

    /**
     * @var  StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var string[]
     */
    protected $mediaAttributesWithLabels = [
        'image',
        'small_image',
        'thumbnail'
    ];

    /**
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param AttributeValue $attributeValue
     * @param Database $fileStorageDb
     * @param Filesystem $filesystem
     * @param GalleryResource $resourceModel
     * @param Config $mediaConfig
     * @param MetadataPool $metadataPool
     * @param StoreManagerInterface $storeManager
     * @throws FileSystemException
     * @throws Exception
     */
    public function __construct(
        ProductAttributeRepositoryInterface $attributeRepository,
        AttributeValue $attributeValue,
        Database $fileStorageDb,
        Filesystem $filesystem,
        GalleryResource $resourceModel,
        Config $mediaConfig,
        MetadataPool $metadataPool,
        StoreManagerInterface $storeManager
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->attributeValue = $attributeValue;
        $this->fileStorageDb = $fileStorageDb;
        $this->resourceModel = $resourceModel;
        $this->mediaConfig = $mediaConfig;
        $this->storeManager = $storeManager;

        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->metadata = $metadataPool->getMetadata(ProductInterface::class);
    }

    /**
     * @param string $file
     * @return bool
     * @throws LocalizedException
     */
    public function canDeleteImage(string $file): bool
    {
        $catalogPath = $this->mediaConfig->getBaseMediaPath();
        return $this->mediaDirectory->isFile($catalogPath . $file)
            && $this->resourceModel->countImageUses($file) <= 1;
    }

    /**
     * @param ProductInterface $product
     * @param string $imageFile
     * @return bool
     */
    public function canRemoveImage(ProductInterface $product, string $imageFile): bool
    {
        $canRemoveImage = true;
        $gallery = $this->getImagesForAllStores($product);
        $storeId = $product->getStoreId();
        $storeIds = [];
        $storeIds[] = 0;
        $websiteIds = array_map('intval', $product->getWebsiteIds() ?? []);

        foreach ($this->storeManager->getStores() as $store) {
            if (in_array((int) $store->getWebsiteId(), $websiteIds, true)) {
                $storeIds[] = (int) $store->getId();
            }
        }

        if (!empty($gallery)) {
            foreach ($gallery as $image) {
                if (in_array((int) $image['store_id'], $storeIds)
                    && $image['filepath'] === $imageFile
                    && (int) $image['store_id'] !== $storeId
                ) {
                    $canRemoveImage = false;
                }
            }
        }

        return $canRemoveImage;
    }

    /**
     * @param string $file
     * @return string
     * @throws LocalizedException
     */
    public function copyImage(string $file): string
    {
        try {
            $destinationFile = $this->getUniqueFileName($file);

            if (!$this->mediaDirectory->isFile($this->mediaConfig->getMediaPath($file))) {
                // phpcs:ignore Magento2.Exceptions.DirectThrow
                throw new Exception();
            }

            if ($this->fileStorageDb->checkDbUsage()) {
                $this->fileStorageDb->copyFile(
                    $this->mediaDirectory->getAbsolutePath($this->mediaConfig->getMediaShortUrl($file)),
                    $this->mediaConfig->getMediaShortUrl($destinationFile)
                );
                $this->mediaDirectory->delete($this->mediaConfig->getMediaPath($destinationFile));
            } else {
                $this->mediaDirectory->copyFile(
                    $this->mediaConfig->getMediaPath($file),
                    $this->mediaConfig->getMediaPath($destinationFile)
                );
            }

            return str_replace('\\', '/', $destinationFile);
            // phpcs:ignore Magento2.Exceptions.ThrowCatch
        } catch (Exception $e) {
            $file = $this->mediaConfig->getMediaPath($file);
            throw new LocalizedException(
                __('We couldn\'t copy file %1. Please delete media with non-existing images and try again.', $file)
            );
        }
    }

    /**
     * @param ProductInterface $product
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function duplicate(ProductInterface $product): void
    {
        $mediaGalleryData = $product->getData(
            $this->getAttribute()->getAttributeCode()
        );

        if (isset($mediaGalleryData['images']) && is_array($mediaGalleryData['images'])) {
            $this->resourceModel->duplicate(
                $this->getAttribute()->getAttributeId(),
                $mediaGalleryData['duplicate'] ?? [],
                $product->getOriginalLinkId(),
                $product->getData($this->metadata->getLinkField())
            );
        }
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getAttribute()
    {
        if (!$this->attribute) {
            $this->attribute = $this->attributeRepository->get(
                'media_gallery'
            );
        }

        return $this->attribute;
    }

    /**
     * @param string $file
     * @return string
     */
    public function getFilenameFromTmp(string $file): string
    {
        return strrpos($file, self::TMP_SUFFIX) == strlen($file) - 4 ? substr($file, 0, strlen($file) - 4) : $file;
    }

    /**
     * @param ProductInterface $product
     * @return array
     */
    public function getImagesForAllStores(ProductInterface $product): array
    {
        if ($this->imagesGallery ===  null) {
            $storeIds = array_keys($this->storeManager->getStores());
            $storeIds[] = 0;

            $this->imagesGallery = $this->resourceModel->getProductImages($product, $storeIds);
        }

        return $this->imagesGallery;
    }

    /**
     * @return array
     */
    public function getMediaAttributeCodes(): array
    {
        if ($this->mediaAttributeCodes === null) {
            $this->mediaAttributeCodes = $this->mediaConfig->getMediaAttributeCodes();
        }

        return $this->mediaAttributeCodes;
    }

    /**
     * @param ProductInterface $product
     * @param string $attributeCode
     * @param int|null $storeId
     * @return string|null
     */
    public function getMediaAttributeStoreValue(
        ProductInterface $product,
        string $attributeCode,
        int $storeId = null
    ): ?string {
        $gallery = $this->getImagesForAllStores($product);
        $storeId = $storeId === null ? (int) $product->getStoreId() : $storeId;

        foreach ($gallery as $image) {
            if ($image['attribute_code'] === $attributeCode && ((int)$image['store_id']) === $storeId) {
                return $image['filepath'];
            }
        }

        return null;
    }

    /**
     * @param string $file
     * @return string
     */
    public function getSafeFilename(string $file): string
    {
        $file = DIRECTORY_SEPARATOR . ltrim($file, DIRECTORY_SEPARATOR);

        return $this->mediaDirectory->getDriver()->getRealPathSafety($file);
    }

    /**
     * @param string $fileName
     * @return string
     */
    public function getNewFileName(string $fileName): string
    {
        return FileUploader::getNewFileName($fileName);
    }

    /**
     * @param string $file
     * @param bool $forTmp
     * @return string
     */
    public function getUniqueFileName(string $file, $forTmp = false): string
    {
        if ($this->fileStorageDb->checkDbUsage()) {
            $destFile = $this->fileStorageDb->getUniqueFilename(
                $this->mediaConfig->getBaseMediaUrlAddition(),
                $file
            );
        } else {
            $destinationFile = $forTmp
                ? $this->mediaDirectory->getAbsolutePath($this->mediaConfig->getTmpMediaPath($file))
                : $this->mediaDirectory->getAbsolutePath($this->mediaConfig->getMediaPath($file));
            // phpcs:disable Magento2.Functions.DiscouragedFunction
            $destFile = dirname($file) . '/' . $this->getNewFileName($destinationFile);
        }

        return $destFile;
    }

    /**
     * @param string $file
     * @return string
     * @throws FileSystemException
     */
    public function moveImageFromTmp(string $file): string
    {
        $file = $this->getFilenameFromTmp($this->getSafeFilename($file));
        $destinationFile = $this->getUniqueFileName($file);

        if ($this->fileStorageDb->checkDbUsage()) {
            $this->fileStorageDb->renameFile(
                $this->mediaConfig->getTmpMediaShortUrl($file),
                $this->mediaConfig->getMediaShortUrl($destinationFile)
            );

            $this->mediaDirectory->delete($this->mediaConfig->getTmpMediaPath($file));
            $this->mediaDirectory->delete($this->mediaConfig->getMediaPath($destinationFile));
        } else {
            $this->mediaDirectory->renameFile(
                $this->mediaConfig->getTmpMediaPath($file),
                $this->mediaConfig->getMediaPath($destinationFile)
            );
        }

        return str_replace('\\', '/', $destinationFile);
    }

    /**
     * @param ProductInterface $product
     * @param array $images
     * @throws LocalizedException
     */
    public function processDeletedImages(ProductInterface $product, array &$images): void
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
     * @param ProductInterface $product
     * @param array $images
     */
    public function processExistingImages(ProductInterface $product, array &$images): void
    {
        foreach ($images as &$image) {
            $existingData = $this->resourceModel->loadDataFromTableByValueId(GalleryResource::GALLERY_VALUE_TABLE, [$image['value_id']], $product->getStoreId());

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
     * @param ProductInterface $product
     * @param array $existImages
     * @param array $newImages
     * @param array $clearImages
     * @return void
     */
    public function processMediaAttributes(
        ProductInterface $product,
        array $existImages,
        array $newImages,
        array $clearImages
    ): void {
        foreach ($this->getMediaAttributeCodes() as $mediaAttrCode) {
            $this->processMediaAttribute(
                $product,
                $mediaAttrCode,
                $clearImages,
                $newImages
            );
            if (in_array($mediaAttrCode, $this->mediaAttributesWithLabels)) {
                $this->processMediaAttributeLabel(
                    $product,
                    $mediaAttrCode,
                    $clearImages,
                    $newImages,
                    $existImages
                );
            }
        }
    }

    /**
     * @param ProductInterface $product
     * @param array $images
     * @throws LocalizedException
     */
    public function processNewImages(ProductInterface $product, array &$images): void
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
     * @param ProductInterface $product
     * @param array $images
     * @throws LocalizedException
     */
    protected function deleteMediaAttributeValues(ProductInterface $product, array $images): void
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

    /**
     * @param ProductInterface $product
     * @param string $mediaAttrCode
     * @param array $clearImages
     * @param array $newImages
     */
    protected function processMediaAttribute(
        ProductInterface $product,
        string $mediaAttrCode,
        array $clearImages,
        array $newImages
    ): void {
        $storeId = $product->isObjectNew() ? Store::DEFAULT_STORE_ID : (int) $product->getStoreId();
        /***
         * Attributes values are saved as default value in single store mode
         * @see \Magento\Catalog\Model\ResourceModel\AbstractResource::_saveAttributeValue
         */
        if ($storeId === Store::DEFAULT_STORE_ID
            || $this->storeManager->hasSingleStore()
            || $this->getMediaAttributeStoreValue($product, $mediaAttrCode, $storeId) !== null
        ) {
            $value = $product->getData($mediaAttrCode);
            $newValue = $value;
            if (in_array($value, $clearImages)) {
                $newValue = 'no_selection';
            }
            if (in_array($value, array_keys($newImages))) {
                $newValue = $newImages[$value]['new_file'];
            }
            $product->setData($mediaAttrCode, $newValue);
            $product->addAttributeUpdate(
                $mediaAttrCode,
                $newValue,
                $storeId
            );
        }
    }

    /**
     * @param ProductInterface $product
     * @param string $mediaAttrCode
     * @param array $clearImages
     * @param array $newImages
     * @param array $existImages
     * @return void
     */
    protected function processMediaAttributeLabel(
        ProductInterface $product,
        string $mediaAttrCode,
        array $clearImages,
        array $newImages,
        array $existImages
    ): void {
        $resetLabel = false;
        $attrData = $product->getData($mediaAttrCode);
        if (in_array($attrData, $clearImages)) {
            $product->setData($mediaAttrCode . '_label', null);
            $resetLabel = true;
        }

        if (in_array($attrData, array_keys($newImages))) {
            $product->setData($mediaAttrCode . '_label', $newImages[$attrData]['label']);
        }

        if (in_array($attrData, array_keys($existImages)) && isset($existImages[$attrData]['label'])) {
            $product->setData($mediaAttrCode . '_label', $existImages[$attrData]['label']);
        }

        if ($attrData === 'no_selection' && !empty($product->getData($mediaAttrCode . '_label'))) {
            $product->setData($mediaAttrCode . '_label', null);
            $resetLabel = true;
        }
        if (!empty($product->getData($mediaAttrCode . '_label'))
            || $resetLabel === true
        ) {
            $product->addAttributeUpdate(
                $mediaAttrCode . '_label',
                $product->getData($mediaAttrCode . '_label'),
                $product->getStoreId()
            );
        }
    }

    /**
     * @param ProductInterface $product
     * @param array $image
     * @return array
     * @throws LocalizedException
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

            $this->resourceModel->saveDataRow(GalleryResource::GALLERY_TABLE, $data);
        }

        return $data;
    }

    /**
     * @param array $files
     * @throws FileSystemException
     */
    protected function removeDeletedImages(array $files): void
    {
        $catalogPath = $this->mediaConfig->getBaseMediaPath();

        foreach ($files as $filePath) {
            $this->mediaDirectory->delete($catalogPath . '/' . $filePath);
        }
    }
}
