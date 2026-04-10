<?php

declare(strict_types=1);

namespace MailCampaigns\AbandonedCart;

use Doctrine\DBAL\Connection;
use MailCampaigns\AbandonedCart\Migration\Migration1725548117CreateAbandonedCartTable;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskDefinition;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

/**
 * LeadCollect Abandoned Cart Plugin
 * 
 * Captures abandoned shopping carts and sends them to LeadCollect for 
 * automated recovery via physical postcards with personalized coupon codes.
 * 
 * @author Twan Haverkamp <twan@mailcampaigns.nl>
 * @author LeadCollect Team
 */
class MailCampaignsAbandonedCart extends Plugin
{
    private const RESTORE_PAGE_SOURCE = '/Resources/public/leadcollect/restore.php';
    private const RESTORE_PAGE_TARGET = '/leadcollect/restore.php';

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->installRestorePage();
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Remove restore page
        $this->uninstallRestorePage();

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $container = $this->container;
        $connection = $container->get(Connection::class);

        $migration = new Migration1725548117CreateAbandonedCartTable();
        $migration->updateDestructive($connection);
    }

    /**
     * Install the cart restore page to public directory
     */
    private function installRestorePage(): void
    {
        $sourceFile = $this->getPath() . self::RESTORE_PAGE_SOURCE;
        $targetDir = $this->getPublicDirectory();
        $targetFile = $targetDir . self::RESTORE_PAGE_TARGET;

        if (!file_exists($sourceFile)) {
            return;
        }

        // Create target directory if it doesn't exist
        $targetFolder = dirname($targetFile);
        if (!is_dir($targetFolder)) {
            mkdir($targetFolder, 0755, true);
        }

        // Copy the restore page
        copy($sourceFile, $targetFile);
    }

    /**
     * Remove the cart restore page from public directory
     */
    private function uninstallRestorePage(): void
    {
        $targetDir = $this->getPublicDirectory();
        $targetFile = $targetDir . self::RESTORE_PAGE_TARGET;

        if (file_exists($targetFile)) {
            unlink($targetFile);
        }

        // Remove the leadcollect folder if empty
        $targetFolder = dirname($targetFile);
        if (is_dir($targetFolder) && count(scandir($targetFolder)) === 2) {
            rmdir($targetFolder);
        }
    }

    /**
     * Get the Shopware public directory path
     */
    private function getPublicDirectory(): string
    {
        // Go up from plugin path to find public directory
        // Plugin path: /var/www/shop/custom/plugins/MailCampaignsAbandonedCart
        // Public path: /var/www/shop/public
        $pluginPath = $this->getPath();
        $shopRoot = dirname(dirname(dirname($pluginPath)));
        
        return $shopRoot . '/public';
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        parent::deactivate($deactivateContext);

        // Unschedule all tasks related to this plugin
        $container = $this->container;
        /** @var EntityRepository $scheduledTaskRepository */
        $scheduledTaskRepository = $container->get('scheduled_task.repository');

        // Dynamically fetch all tasks registered by this plugin
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->addFilter(new ContainsFilter('name', 'mailcampaigns.abandoned_cart'));

        $existingTasks = $scheduledTaskRepository->search($criteria, $deactivateContext->getContext());

        if ($existingTasks->getTotal() > 0) {
            $updates = [];
            foreach ($existingTasks as $task) {
                $updates[] = [
                    'id' => $task->getUniqueIdentifier(),
                    'status' => ScheduledTaskDefinition::STATUS_INACTIVE,
                ];
            }

            $scheduledTaskRepository->update($updates, $deactivateContext->getContext());
        }
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        // Ensure restore page is installed
        $this->installRestorePage();

        // Reschedule all tasks related to this plugin
        $container = $this->container;
        /** @var EntityRepository $scheduledTaskRepository */
        $scheduledTaskRepository = $container->get('scheduled_task.repository');

        // Dynamically fetch all tasks registered by this plugin
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->addFilter(new ContainsFilter('name', 'mailcampaigns.abandoned_cart'));

        $existingTasks = $scheduledTaskRepository->search($criteria, $activateContext->getContext());

        if ($existingTasks->getTotal() > 0) {
            $updates = [];
            foreach ($existingTasks as $task) {
                $updates[] = [
                    'id' => $task->getUniqueIdentifier(),
                    'status' => ScheduledTaskDefinition::STATUS_SCHEDULED,
                ];
            }

            $scheduledTaskRepository->update($updates, $activateContext->getContext());
        }
    }

    public function postInstall(InstallContext $installContext): void
    {
        parent::postInstall($installContext);
        
        // Ensure restore page exists after all installation steps
        $this->installRestorePage();
    }

    public function postUpdate(\Shopware\Core\Framework\Plugin\Context\UpdateContext $updateContext): void
    {
        parent::postUpdate($updateContext);
        
        // Reinstall restore page on update (in case of changes)
        $this->installRestorePage();
    }
}
