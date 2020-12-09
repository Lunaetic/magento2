<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\Product\Gallery;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Repository;
use Magento\Catalog\Model\Product\Gallery\CreateHandler;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\Entity\Attribute;
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
    protected $attributeRepositoryMock;

    /**
     * @var Database|MockObject
     */
    protected $filestorageDbMock;

    /**
     * @var Filesystem|MockObject
     */
    protected $filesystemMock;

    /**
     * @var Json|MockObject
     */
    protected $jsonMock;

    /**
     * @var Config|MockObject
     */
    protected $mediaConfigMock;

    /**
     * @var Write|MockObject
     */
    protected $mediaDirectoryMock;

    /**
     * @var EntityMetadata|MockObject
     */
    protected $metadataMock;

    /**
     * @var MetadataPool|MockObject
     */
    protected $metadataPoolMock;

    /**
     * @var CreateHandler
     */
    protected $model;

    /**
     * @var Gallery|MockObject
     */
    protected $resourceModelMock;

    /**
     * @var StoreManager|MockObject
     */
    protected $storeManagerMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->attributeRepositoryMock = $this->createPartialMock(
            Repository::class,
            ['get']
        );

        $this->filestorageDbMock = $this->createPartialMock(
            Database::class,
            ['checkDbUsage', 'getUniqueFilename', 'renameFile']
        );

        $this->filesystemMock = $this->createPartialMock(
            Filesystem::class,
            ['getDirectoryWrite']
        );

        $this->jsonMock = $this->createPartialMock(
            Json::class,
            ['unserialize']
        );

        $this->metadataMock = $this->createPartialMock(
            EntityMetadata::class,
            []
        );

        $this->mediaConfigMock = $this->createPartialMock(
            Config::class,
            [
                'getBaseMediaUrlAddition',
                'getMediaAttributeCodes',
                'getMediaPath',
                'getMediaShortUrl',
                'getTmpMediaPath',
                'getTmpMediaShortUrl']
        );

        $this->mediaDirectoryMock = $this->createPartialMock(
            Write::class,
            ['getAbsolutePath', 'getDriver']
        );

        $this->metadataPoolMock = $this->createPartialMock(
            MetadataPool::class,
            ['getMetadata']
        );

        $this->storeManagerMock = $this->createPartialMock(
            StoreManager::class,
            ['getStores', 'hasSingleStore']
        );

        $this->resourceModelMock = $this->createPartialMock(
            Gallery::class,
            ['getProductImages']
        );

        $this->model = $this->createPartialMock(
            CreateHandler::class,
            ['getNewFileName']
        );

        $this->setPropertyValues(
            $this->model,
            [
                'attributeRepository' => $this->attributeRepositoryMock,
                'fileStorageDb' => $this->filestorageDbMock,
                'json' => $this->jsonMock,
                'mediaConfig' => $this->mediaConfigMock,
                'mediaDirectory' => $this->mediaDirectoryMock,
                'metadata' => $this->metadataMock,
                'resourceModel' => $this->resourceModelMock,
                'storeManager' => $this->storeManagerMock
            ]
        );
    }

    /**
     * @throws LocalizedException
     */
    public function testExecuteValueNotArray()
    {
        $attribute = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode']
        );

        $attribute->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn('media_gallery');

        $this->attributeRepositoryMock->expects($this->once())
            ->method('get')
            ->with('media_gallery')
            ->willReturn($attribute);

        $productMock = $this->createPartialMock(
            Product::class,
            ['getData']
        );

        $productMock->expects($this->once())
            ->method('getData')
            ->with('media_gallery')
            ->willReturn(null);

        $returnValue = $this->model->execute($productMock, []);

        $this->assertSame($returnValue, $productMock);
    }

    /**
     * @throws LocalizedException
     */
    public function testExecuteValueNotContainImages()
    {
        $attribute = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode']
        );

        $attribute->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn('media_gallery');

        $this->attributeRepositoryMock->expects($this->once())
            ->method('get')
            ->with('media_gallery')
            ->willReturn($attribute);

        $productMock = $this->createPartialMock(
            Product::class,
            ['getData']
        );

        $productMock->expects($this->once())
            ->method('getData')
            ->with('media_gallery')
            ->willReturn(['testKey' => 'testValue']);

        $returnValue = $this->model->execute($productMock, []);

        $this->assertSame($returnValue, $productMock);
    }

    /**
     * @throws LocalizedException
     */
    public function testExecuteValueContainsJsonNotDuplicateNotDbStorageSingleStore()
    {
        $productMock = $this->createPartialMock(
            Product::class,
            ['addAttributeUpdate', 'getData', 'getIsDuplicate', 'isObjectNew', 'setData']
        );

        $attributeMock = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode']
        );

        $attributeMock->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn('media_gallery');

        $this->attributeRepositoryMock->expects($this->once())
            ->method('get')
            ->with('media_gallery')
            ->willReturn($attributeMock);

        $productMock->expects($this->exactly(4))
            ->method('getData')
            ->withConsecutive(
                ['media_gallery'],
                ['image']
            )
            ->willReturnOnConsecutiveCalls(
                ['images' => '{"9rm40a2fvqd":{"position":"1","media_type":"image","video_provider":"","file":"\/k\/i\/test.jpeg.tmp","value_id":"","label":"","disabled":"0","removed":"","video_url":"","video_title":"","video_description":"","video_metadata":"","role":""}}'],
                '/h/e/headshot.jpeg.tmp'
            );

        $productMock->expects($this->once())
            ->method('getIsDuplicate')
            ->willReturn(false);

        $this->jsonMock->expects($this->once())
            ->method('unserialize')
            ->with('{"9rm40a2fvqd":{"position":"1","media_type":"image","video_provider":"","file":"\/k\/i\/test.jpeg.tmp","value_id":"","label":"","disabled":"0","removed":"","video_url":"","video_title":"","video_description":"","video_metadata":"","role":""}}')
            ->willReturn([
                "9rm40a2fvqd" => [
                    "position" => "1",
                    "media_type" => "image",
                    "video_provider" => "",
                    "file" => "/k/i/test.jpeg.tmp",
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

        $driverMock = $this->createPartialMock(
            File::class,
            ['getRealPathSafety']
        );

        $this->mediaDirectoryMock->expects($this->once())
            ->method('getDriver')
            ->willReturn($driverMock);

        $driverMock->expects($this->once())
            ->method('getRealPathSafety')
            ->with("/k/i/test.jpeg.tmp")
            ->willReturn('/k/i/test.jpeg.tmp');

        $this->filestorageDbMock->expects($this->exactly(2))
            ->method('checkDbUsage')
            ->willReturn(false);

        $this->mediaConfigMock->expects($this->once())
            ->method('getMediaPath')
            ->willReturn('/k/i/test.jpeg');

        $this->mediaDirectoryMock->expects($this->once())
            ->method('getAbsolutePath')
            ->with('/k/i/test.jpeg')
            ->willReturn('B');

        $this->mediaConfigMock->expects($this->once())
            ->method('getBaseMediaUrlAddition')
            ->willReturn('catalog/product');

        $this->filestorageDbMock->expects($this->once())
            ->method('getUniqueFilename')
            ->with('catalog/product', '/k/i/test.jpeg')
            ->willReturn('catalog/product/k/i/test.jpeg');

        $this->filestorageDbMock->expects($this->once())
            ->method('renameFile')
            ->with('tmpMediaShortUrl', 'mediaShortUrl');

        $this->mediaConfigMock->expects($this->once())
            ->method('getTmpMediaShortUrl')
            ->with('/k/i/test.jpeg')
            ->willReturn('tmpMediaShortUrl');

        $this->mediaConfigMock->expects($this->once())
            ->method('getMediaShortUrl')
            ->with('catalog/product/k/i/test.jpeg')
            ->willReturn('mediaShortUrl');

        $this->mediaConfigMock->expects($this->once())
            ->method('getTmpMediaPath')
            ->with('/k/i/test.jpeg')
            ->willReturn('/k/i/test.jpeg');

        $this->mediaConfigMock->expects($this->once())
            ->method('getMediaPath')
            ->with('/k/i/test.jpeg')
            ->willReturn('/k/i/test.jpeg');

        $this->mediaConfigMock->expects($this->once())
            ->method('getMediaAttributeCodes')
            ->willReturn(["image", "small_image", "thumbnail", "swatch_image"]);

        $productMock->expects($this->once())
            ->method("isObjectNew")
            ->willReturn(true);

        $this->storeManagerMock->expects($this->once())
            ->method('hasSingleStore')
            ->willReturn(true);

        $this->storeManagerMock->expects($this->once())
            ->method('getStores')
            ->willReturn([]);

        $this->resourceModelMock->expects($this->once())
            ->method('getProductImages')
            ->with($productMock, [0])
            ->willReturn([]);

        $productMock->expects($this->once())
            ->method('setData')
            ->with('image', '/k/i/test.jpg');

        $productMock->expects($this->once())
            ->method('addAttributeUpdate')
            ->with('image', '', 0);

        $this->model->execute($productMock, []);
    }

    /**
     * Set property values using reflection
     *
     * @param $object
     * @param $propertyValueArray
     * @return mixed
     * @throws \ReflectionException
     */
    protected function setPropertyValues(&$object, $propertyValueArray)
    {
        $reflection = new \ReflectionClass(get_class($object));

        foreach ($propertyValueArray as $property => $value) {
            $reflectionProperty = $reflection->getProperty($property);
            $reflectionProperty->setAccessible(true);
            $reflectionProperty->setValue($object, $value);
        }

        return $object;
    }
}
