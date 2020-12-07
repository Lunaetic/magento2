<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\Product\Gallery;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Repository;
use Magento\Catalog\Model\Product\Gallery\CreateHandler;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\EntityManager\EntityMetadata;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Store\Model\StoreManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Catalog Product Gallery CreateHandler
 */
class CreateHandlerTest extends TestCase
{
    /**
    * @var Repository|MockObject
    */
    protected $attributeRepository;

    /**
     * @var Database|MockObject
     */
    protected $filestorageDb;

    /**
     * @var Filesystem|MockObject
     */
    protected $filesystem;

    /**
     * @var Json|MockObject
     */
    protected $json;

    /**
     * @var Config|MockObject
     */
    protected $mediaConfig;

    /**
     * @var Write|MockObject
     */
    protected $mediaDirectory;

    /**
     * @var EntityMetadata|MockObject
     */
    protected $metadata;

    /**
     * @var MetadataPool|MockObject
     */
    protected $metadataPool;

    /**
     * @var CreateHandler
     */
    protected $model;

    /**
     * @var Gallery|MockObject
     */
    protected $resourceModel;

    /**
     * @var StoreManager|MockObject
     */
    protected $storeManager;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->attributeRepository = $this->createPartialMock(
            Repository::class,
            ['get']
        );

        $this->filestorageDb = $this->createMock(
            Database::class
        );

        $this->filesystem = $this->createMock(
            Filesystem::class
        );

        $this->filesystem->expects($this->once())
            ->method('getDirectoryWrite')
            ->with(DirectoryList::MEDIA)
            ->willReturn($this->mediaDirectory);

        $this->json = $this->createMock(
            Json::class
        );

        $this->metadata = $this->createMock(
            EntityMetadata::class
        );

        $this->mediaConfig = $this->createMock(
            Config::class
        );

        $this->mediaDirectory = $this->createMock(
            Write::class
        );

        $this->metadataPool = $this->createPartialMock(
            MetadataPool::class,
            ['getMetadata']
        );

        $this->metadataPool->expects($this->once())
            ->method('getMetadata')
            ->with(ProductInterface::class)
            ->willReturn($this->metadata);

        $this->storeManager = $this->createPartialMock(
            StoreManager::class,
            ['getStores']
        );

        $this->resourceModel = $this->createMock(
            Gallery::class
        );

        $this->model = new CreateHandler(
            $this->metadataPool,
            $this->attributeRepository,
            $this->resourceModel,
            $this->json,
            $this->mediaConfig,
            $this->filesystem,
            $this->filestorageDb,
            $this->storeManager
        );

        // 'mediaDirectory' is `protected` and set in the constructor via expression; set it with reflection here
        $reflection = new \ReflectionClass(CreateHandler::class);
        $reflectionProperty = $reflection->getProperty('mediaDirectory');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->model, $this->mediaDirectory);
    }

    /**
     * @return array
     */
    public function executeDataProvider()
    {
        return [
            [
                'imageData' => [
                ]
            ]
        ];
    }

    /**
     * @throws LocalizedException
     */
    public function testExecuteValueNotArray()
    {
        $attributeCode = 'media_gallery';
        $attribute = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode']
        );

        $attribute->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn($attributeCode);

        $this->attributeRepository->expects($this->once())
            ->method('get')
            ->with($attributeCode)
            ->willReturn($attribute);

        $productMock = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->getMock();

        $productMock->expects($this->once())
            ->method('getData')
            ->with($attributeCode)
            ->willReturn(null);

        $returnValue = $this->model->execute($productMock, []);

        $this->assertSame($returnValue, $productMock);
    }

    /**
     * @throws LocalizedException
     */
    public function testExecuteValueNotContainImages()
    {
        $attributeCode = 'media_gallery';
        $attribute = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode']
        );

        $attribute->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn($attributeCode);

        $this->attributeRepository->expects($this->once())
            ->method('get')
            ->with($attributeCode)
            ->willReturn($attribute);

        $productMock = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->getMock();

        $productMock->expects($this->once())
            ->method('getData')
            ->with($attributeCode)
            ->willReturn(['testKey' => 'testValue']);

        $returnValue = $this->model->execute($productMock, []);

        $this->assertSame($returnValue, $productMock);
    }

    /**
     * @throws LocalizedException
     */
    public function testExecuteValueContainsJson()
    {
        $attributeCode = 'media_gallery';
        $attribute = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode']
        );

        $attribute->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn($attributeCode);

        $this->attributeRepository->expects($this->once())
            ->method('get')
            ->with($attributeCode)
            ->willReturn($attribute);

        $productMock = $this->createPartialMock(
            Product::class,
            ['getData', 'getIsDuplicate']
        );

        $productMock->expects($this->once())
            ->method('getData')
            ->with($attributeCode)
            ->willReturn(['images' => '{"9rm40a2fvqd":{"position":"1","media_type":"image","video_provider":"","file":"\/k\/i\/kitteh_1.jpeg.tmp","value_id":"","label":"","disabled":"0","removed":"","video_url":"","video_title":"","video_description":"","video_metadata":"","role":""}}']);

        $this->json->expects($this->once())
            ->method('unserialize')
            ->with('{"9rm40a2fvqd":{"position":"1","media_type":"image","video_provider":"","file":"\/k\/i\/kitteh_1.jpeg.tmp","value_id":"","label":"","disabled":"0","removed":"","video_url":"","video_title":"","video_description":"","video_metadata":"","role":""}}')
            ->willReturn([
                "9rm40a2fvqd" => [
                    "position" => "1",
                    "media_type" => "image",
                    "video_provider" => "",
                    "file" => "/k/i/kitteh_1.jpeg.tmp",
                    "value_id" => "",
                    "label" => "",
                    "disabled" => "0",
                    "removed" => "",
                    "video_url" => "",
                    "video_title" => "",
                    "video_description" => "",
                    "video_metadata" => "",
                    "role" => ""
                ]
            ]);

        $productMock->expects($this->once())
            ->method('getIsDuplicate')
            ->willReturn(false);

        $driver = $this->createPartialMock(
            File::class,
            ['getRealPathSafety']
        );

        $this->mediaDirectory->expects($this->once())
            ->method('getDriver')
            ->willReturn($driver);

        $driver->expects($this->once())
            ->method('getRealPathSafety')
            ->with("/k/i/kitteh_1.jpeg.tmp")
            ->willReturn('/k/i/kitteh_1.jpeg.tmp');

        $this->filestorageDb->expects($this->exactly(2))
            ->method('checkDbUsage')
            ->willReturn(true);

        $this->mediaConfig->expects($this->once())
            ->method('getBaseMediaUrlAddition')
            ->willReturn('catalog/product');

        $this->filestorageDb->expects($this->once())
            ->method('getUniqueFilename')
            ->with('catalog/product', '/k/i/kitteh_1.jpeg')
            ->willReturn('catalog/product/k/i/kitteh_1.jpeg');

        $this->filestorageDb->expects($this->once())
            ->method('renameFile')
            ->with('', '');

        $this->mediaConfig->expects($this->once())
            ->method('getTmpMediaShortUrl')
            ->with('/k/i/kitteh_1.jpeg');

        $this->mediaConfig->expects($this->once())
            ->method('getMediaShortUrl')
            ->with('catalog/product/k/i/kitteh_1.jpeg');

        $returnValue = $this->model->execute($productMock, []);

        $this->assertSame($returnValue, $productMock);
    }
}
