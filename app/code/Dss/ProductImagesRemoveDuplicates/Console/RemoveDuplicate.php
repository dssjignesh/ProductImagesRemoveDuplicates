<?php

declare(strict_types=1);

/**
 * Digit Software Solutions.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 *
 * @category  Dss
 * @package   Dss_ProductImagesRemoveDuplicates
 * @author    Extension Team
 * @copyright Copyright (c) 2024 Digit Software Solutions. ( https://digitsoftsol.com )
 */

namespace Dss\ProductImagesRemoveDuplicates\Console;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

class RemoveDuplicate extends Command
{
    /**
     * Constructor.
     *
     * @param State $state
     * @param ProductRepositoryInterface $productRepository
     * @param ProductCollectionFactory $productCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param DirectoryList $directoryList
     * @param ResourceConnection $resource
     * @param FileDriver $fileDriver
     */
    public function __construct(
        private State $state,
        private ProductRepositoryInterface $productRepository,
        private ProductCollectionFactory $productCollectionFactory,
        private StoreManagerInterface $storeManager,
        private DirectoryList $directoryList,
        private ResourceConnection $resource,
        private FileDriver $fileDriver
    ) {
         parent::__construct();
    }

    /**
     * Command configuration.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('duplicate:remove')
            ->setDescription('Remove duplicate product images')
            ->addOption(
                'unlink',
                'u',
                InputOption::VALUE_OPTIONAL,
                'Unlink the duplicate files from the system',
                false
            )
            ->addOption(
                'dryrun',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Dry-run does not delete any values or files',
                true
            )
            ->addArgument(
                'products',
                InputArgument::IS_ARRAY,
                'Product entity SKUs to filter on',
                null
            );
    }

    /**
     * Execute the console command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int Command::SUCCESS
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->state->setAreaCode(Area::AREA_GLOBAL);
        $this->storeManager->setCurrentStore(0);

        $unlink = $input->getOption('unlink') === 'false' ? false : $input->getOption('unlink');
        $dryrun = $input->getOption('dryrun') === 'false' ? false : $input->getOption('dryrun');

        $path = $this->directoryList->getPath('media');
        $productCollection = $this->productCollectionFactory->create();

        if ($input->getArgument('products')) {
            $productCollection->addFieldToFilter('sku', ['in' => $input->getArgument('products')]);
        } else {
            $productCollection->addIdFilter($this->getEntityIds());
        }

        $total = $productCollection->getSize();
        $count = 0;

        if ($dryrun) {
            $output->writeln('THIS IS A DRY-RUN, NO CHANGES WILL BE MADE!');
        }
        $output->writeln($total . ' products found with 2 images or more.');

        foreach ($productCollection as $index => $product) {
            $this->processProduct($product, $path, $unlink, $dryrun, $output, ++$index, $total, $count);
        }

        if ($dryrun) {
            $output->writeln('THIS WAS A DRY-RUN, NO CHANGES WERE MADE!');
        } else {
            $output->writeln('Duplicate images are removed');
        }

        return Command::SUCCESS;
    }

    /**
     * Processes a product to identify and remove duplicate images.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param string $path
     * @param bool|string $unlink
     * @param bool|string $dryrun
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int $index
     * @param int $total
     * @param int $count
     *
     * @return void
     */
    private function processProduct(
        $product,
        $path,
        $unlink,
        $dryrun,
        OutputInterface $output,
        int $index,
        int $total,
        int &$count
    ): void {
        $product = $this->productRepository->getById($product->getId());
        $product->setStoreId(0);
        $md5Values = [];
        $baseImage = $product->getImage();

        if ($baseImage !== 'no_selection') {
            $filepath = $path . '/catalog/product' . $baseImage;
            if ($this->fileDriver->isExists($filepath) && $this->fileDriver->isFile($filepath)) {
                $md5Values[] = md5_file($filepath);
            }

            $output->writeln("Processing product $index of $total");

            $gallery = $product->getMediaGalleryEntries();
            $filepaths = [];

            if ($gallery && count($gallery)) {
                foreach ($gallery as $key => $galleryImage) {
                    if ($galleryImage->getFile() === $baseImage) {
                        continue;
                    }
                    $filepath = $path . '/catalog/product' . $galleryImage->getFile();

                    if (!$this->fileDriver->isExists($filepath) || !$this->fileDriver->isFile($filepath)) {
                        continue;
                    }

                    $md5 = md5_file($filepath);
                    if (in_array($md5, $md5Values)) {
                        if (count($galleryImage->getTypes()) === 0) {
                            unset($gallery[$key]);
                            $filepaths[] = $filepath;
                            $output->writeln('Removed duplicate image from ' . $product->getSku());
                            $count++;
                        }
                    } else {
                        $md5Values[] = $md5;
                    }
                }

                if (!$dryrun && !empty($filepaths)) {
                    $product->setMediaGalleryEntries($gallery);
                    try {
                        $this->productRepository->save($product);
                    } catch (\Exception $e) {
                        $output->writeln('Could not save product: ' . $e->getMessage());
                    }
                }

                foreach ($filepaths as $filepath) {
                    if ($this->fileDriver->isFile($filepath)) {
                        if (!$dryrun && $unlink) {
                            $this->fileDriver->deleteFile($filepath);
                        }
                        if ($unlink) {
                            $output->writeln('Deleted file: ' . $filepath);
                        }
                    }
                }
            }
        }
    }

    /**
     * Retrieve entity IDs with duplicate images.
     *
     * @return array
     */
    public function getEntityIds(): array
    {
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        $select = $connection->select()
            ->from($tableName, ['entity_id'])
            ->group('entity_id')
            ->having('COUNT(entity_id) >= 2');

        $result = $connection->fetchCol($select);

        return $result;
    }
}
