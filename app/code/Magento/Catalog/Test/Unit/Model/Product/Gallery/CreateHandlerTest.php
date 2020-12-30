<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\Product\Gallery;

use Magento\Catalog\Helper\Product\Gallery as GalleryHelper;
use Magento\Catalog\Model\Product\Gallery\CreateHandler;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 */
class CreateHandlerTest extends TestCase
{
    /**
     * @var object
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

        $objectManager = new ObjectManager($this);

        $this->subject = $objectManager->getObject(
            CreateHandler::class,
            [
                'resourceModel' => $this->resourceModelMock,
                'galleryHelper' => $this->galleryHelperMock,
                'json' => $this->jsonMock
            ]
        );
    }
}
