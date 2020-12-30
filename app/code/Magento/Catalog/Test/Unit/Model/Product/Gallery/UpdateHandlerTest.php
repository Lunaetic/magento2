<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\Product\Gallery;

use Magento\Catalog\Helper\Product\Gallery as GalleryHelper;
use Magento\Catalog\Model\Product\Gallery\UpdateHandler;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\ResourceModel\AttributeValue;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 */
class UpdateHandlerTest extends TestCase
{
    /**
     * @var UpdateHandler
     */
    protected $subject;

    /**
     * @var Gallery|MockObject
     */
    protected $resourceModelMock;

    /**
     * @var GalleryHelper|MockObject
     */
    protected $galleryHelperMock;

    /**
     * @var Json|MockObject
     */
    protected $jsonMock;

    /**
     * @var Config|MockObject
     */
    protected $mediaConfigMock;

    /**
     * @var Filesystem|MockObject
     */
    protected $filesystemMock;

    /**
     * @var AttributeValue|MockObject
     */
    protected $attributeValueMock;

    /**
     */
    public function setUp(): void
    {
        $this->resourceModelMock = $this->createPartialMock(
            Gallery::class,
            []
        );

        $this->galleryHelperMock = $this->createPartialMock(
            GalleryHelper::class,
            []
        );

        $this->jsonMock = $this->createPartialMock(
            Json::class,
            []
        );

        $this->mediaConfigMock = $this->createPartialMock(
            Config::class,
            []
        );

        $this->filesystemMock = $this->createPartialMock(
            Filesystem::class,
            []
        );

        $this->attributeValueMock = $this->createPartialMock(
            AttributeValue::class,
            []
        );

        $objectManager = new ObjectManager($this);

        $this->subject = $objectManager->getObject(
            UpdateHandler::class,
            [
                'resourceModel' => $this->resourceModelMock,
                'galleryHelper' => $this->galleryHelperMock,
                'json' => $this->jsonMock,
                'mediaConfig' => $this->mediaConfigMock,
                'filesystem' => $this->filesystemMock,
                'attributeValue' => $this->attributeValueMock
            ]
        );
    }
}
