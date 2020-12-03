<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Model\Product\Gallery;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\EntityManager\EntityMetadata;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\MediaStorage\Model\File\Uploader as FileUploader;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Base handler for catalog product gallery
 *
 * @api
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) // 31121
 * @since 101.0.0 // 31121
 */
class Handler
{
    /**
     * @var EntityMetadata
     * @since 101.0.0 // 31121
     */
    protected $metadata;

    /**
     * @var ProductAttributeInterface
     * @since 101.0.0 // 31121
     */
    protected $attribute;

    /**
     * @var ProductAttributeRepositoryInterface
     * @since 101.0.0 // 31121
     */
    protected $attributeRepository;

    /**
     * Resource model
     *
     * @var Gallery
     * @since 101.0.0 // 31121
     */
    protected $resourceModel;

    /**
     * @var Json
     * @since 101.0.0 // 31121
     */
    protected $json;

    /**
     * @var Config
     * @since 101.0.0 // 31121
     */
    protected $mediaConfig;

    /**
     * @var WriteInterface
     * @since 101.0.0 // 31121
     */
    protected $mediaDirectory;

    /**
     * @var Database
     * @since 101.0.0 // 31121
     */
    protected $fileStorageDb;

    /**
     * @var array
     */
    protected $mediaAttributeCodes;

    /**
     * @var array
     */
    protected $imagesGallery;

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
     * @param MetadataPool $metadataPool
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param Gallery $resourceModel
     * @param Json $json
     * @param Config $mediaConfig
     * @param Filesystem $filesystem
     * @param Database $fileStorageDb
     * @param StoreManagerInterface|null $storeManager
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
        StoreManagerInterface $storeManager = null
    ) {
        $this->metadata = $metadataPool->getMetadata(ProductInterface::class);
        $this->attributeRepository = $attributeRepository;
        $this->resourceModel = $resourceModel;
        $this->json = $json;
        $this->mediaConfig = $mediaConfig;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->fileStorageDb = $fileStorageDb;
        $this->storeManager = $storeManager ?: ObjectManager::getInstance()->get(StoreManagerInterface::class);
    }

    /**
     * Check possibility to remove image
     *
     * @param ProductInterface $product
     * @param string $imageFile
     * @return bool
     */
    protected function canRemoveImage(ProductInterface $product, string $imageFile) :bool
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
     * Copy image and return new filename.
     *
     * @param string $file
     * @return string
     * @throws LocalizedException
     * @since 101.0.0 // 31121
     */
    protected function copyImage($file)
    {
        try {
            $destinationFile = $this->getUniqueFileName($file);

            if (!$this->mediaDirectory->isFile($this->mediaConfig->getMediaPath($file))) {
                // phpcs:ignore Magento2.Exceptions.DirectThrow
                throw new \Exception();
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
        } catch (\Exception $e) {
            $file = $this->mediaConfig->getMediaPath($file);
            throw new LocalizedException(
                __('We couldn\'t copy file %1. Please delete media with non-existing images and try again.', $file)
            );
        }
    }

    /**
     * Duplicate attribute
     *
     * @param Product $product
     * @return $this
     * @throws NoSuchEntityException|LocalizedException
     * @since 101.0.0 // 31121
     */
    protected function duplicate($product)
    {
        $mediaGalleryData = $product->getData(
            $this->getAttribute()->getAttributeCode()
        );

        if (!isset($mediaGalleryData['images']) || !is_array($mediaGalleryData['images'])) {
            return $this;
        }

        $this->resourceModel->duplicate(
            $this->getAttribute()->getAttributeId(),
            $mediaGalleryData['duplicate'] ?? [],
            $product->getOriginalLinkId(),
            $product->getData($this->metadata->getLinkField())
        );

        return $this;
    }

    /**
     * Returns media gallery attribute instance
     *
     * @return ProductAttributeInterface
     * @throws NoSuchEntityException
     * @since 101.0.0 // 31121
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
     * Returns file name according to tmp name
     *
     * @param string $file
     * @return string
     * @since 101.0.0 // 31121
     */
    protected function getFilenameFromTmp($file)
    {
        return strrpos($file, '.tmp') == strlen($file) - 4 ? substr($file, 0, strlen($file) - 4) : $file;
    }

    /**
     * Get product images for all stores
     *
     * @param ProductInterface $product
     * @return array
     */
    protected function getImagesForAllStores(ProductInterface $product)
    {
        if ($this->imagesGallery ===  null) {
            $storeIds = array_keys($this->storeManager->getStores());
            $storeIds[] = 0;

            $this->imagesGallery = $this->resourceModel->getProductImages($product, $storeIds);
        }

        return $this->imagesGallery;
    }

    /**
     * Get Media Attribute Codes cached value
     *
     * @return array
     */
    protected function getMediaAttributeCodes()
    {
        if ($this->mediaAttributeCodes === null) {
            $this->mediaAttributeCodes = $this->mediaConfig->getMediaAttributeCodes();
        }
        return $this->mediaAttributeCodes;
    }

    /**
     * Get media attribute value for store view
     *
     * @param Product $product
     * @param string $attributeCode
     * @param int|null $storeId
     * @return string|null
     */
    private function getMediaAttributeStoreValue(Product $product, string $attributeCode, int $storeId = null): ?string
    {
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
     * Returns safe filename for posted image
     *
     * @param string $file
     * @return string
     */
    protected function getSafeFilename($file)
    {
        $file = DIRECTORY_SEPARATOR . ltrim($file, DIRECTORY_SEPARATOR);

        return $this->mediaDirectory->getDriver()->getRealPathSafety($file);
    }

    /**
     * Check whether file to move exists. Getting unique name
     *
     * @param string $file
     * @param bool $forTmp
     * @return string
     * @since 101.0.0 // 31121
     */
    protected function getUniqueFileName($file, $forTmp = false)
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
            $destFile = dirname($file) . '/' . FileUploader::getNewFileName($destinationFile);
        }

        return $destFile;
    }

    /**
     * Move image from temporary directory to normal
     *
     * @param string $file
     * @return string
     * @throws FileSystemException
     * @since 101.0.0 // 31121
     */
    protected function moveImageFromTmp($file)
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
     * Process media attribute
     *
     * @param Product $product
     * @param string $mediaAttrCode
     * @param array $clearImages
     * @param array $newImages
     */
    private function processMediaAttribute(
        Product $product,
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
     * Update media attributes
     *
     * @param Product $product
     * @param array $existImages
     * @param array $newImages
     * @param array $clearImages
     */
    protected function processMediaAttributes(
        Product $product,
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
     * Process media attribute label
     *
     * @param Product $product
     * @param string $mediaAttrCode
     * @param array $clearImages
     * @param array $newImages
     * @param array $existImages
     */
    private function processMediaAttributeLabel(
        Product $product,
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
}
