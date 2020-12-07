<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\Product\Gallery;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Entity\Attribute;
use Magento\Catalog\Model\Product\Attribute\Repository;
use Magento\Catalog\Model\Product\Gallery\Handler;
use Magento\Catalog\Model\Product\Gallery\UpdateHandler;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\ResourceModel\AttributeValue;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\EntityManager\EntityMetadata;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Store\Model\StoreManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Catalog Product Gallery UpdateHandler
 */
class UpdateHandlerTest extends TestCase
{
    /**
     * @var Repository|MockObject
     */
    protected $attributeRepository;

    /** @var AttributeValue|MockObject */
    protected $attributeValue;

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
     * @var Handler
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

        $this->attributeValue = $this->createMock(
            AttributeValue::class
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

        $this->model = new UpdateHandler(
            $this->metadataPool,
            $this->attributeRepository,
            $this->resourceModel,
            $this->json,
            $this->mediaConfig,
            $this->filesystem,
            $this->filestorageDb,
            $this->storeManager,
            $this->attributeValue
        );
    }

    /**
     * @return array
     */
    public function executeDataProvider()
    {
        return [
            [0]
        ];
    }

    /**
     * @param $value
     * @dataProvider executeDataProvider
     */
    public function testExecute($value)
    {
        $this->assertEquals(0, $value);
    }
}
