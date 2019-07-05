<?php declare(strict_types=1);

namespace Shopware\Storefront\Page\Product\Configurator;

use Shopware\Core\Content\Product\Aggregate\ProductConfiguratorSetting\ProductConfiguratorSettingEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ProductPageConfiguratorLoader
{
    /**
     * @var EntityRepositoryInterface
     */
    private $configuratorRepository;

    /**
     * @var AvailableCombinationLoader
     */
    private $combinationLoader;

    public function __construct(
        EntityRepositoryInterface $configuratorRepository,
        AvailableCombinationLoader $combinationLoader
    ) {
        $this->combinationLoader = $combinationLoader;
        $this->configuratorRepository = $configuratorRepository;
    }

    /**
     * @throws InconsistentCriteriaIdsException
     */
    public function load(
        SalesChannelProductEntity $product,
        SalesChannelContext $salesChannelContext
    ): PropertyGroupCollection {
        if (!$product->getParentId()) {
            return new PropertyGroupCollection();
        }

        $groups = $this->loadSettings($product, $salesChannelContext);

        $groups = $this->sortSettings($product, $groups);

        $combinations = $this->combinationLoader->load(
            $product->getParentId(),
            $salesChannelContext->getContext()
        );

        $current = $this->buildCurrentOptions($product, $groups);

        /** @var PropertyGroupEntity $group */
        foreach ($groups as $group) {
            $options = $group->getOptions();
            if ($group->getOptions() === null) {
                continue;
            }

            foreach ($options as $option) {
                $combinable = $this->isCombinable($option, $current, $combinations);
                if ($combinable === null) {
                    $group->getOptions()->remove($option->getId());
                    continue;
                }

                $option->setCombinable($combinable);
            }
        }

        return $groups;
    }

    /**
     * @throws InconsistentCriteriaIdsException
     */
    private function loadSettings(SalesChannelProductEntity $product, SalesChannelContext $context): ?array
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter('product_configurator_setting.productId', $product->getParentId() ?? $product->getId())
        );

        $criteria->addAssociationPath('option.group')
            ->addAssociationPath('option.media')
            ->addAssociation('product_configurator_setting.media');

        $settings = $this->configuratorRepository
            ->search($criteria, $context->getContext())
            ->getEntities();

        if ($settings->count() <= 0) {
            return null;
        }
        $groups = [];

        /** @var ProductConfiguratorSettingEntity $setting */
        foreach ($settings as $setting) {
            $option = $setting->getOption();
            if ($option === null) {
                continue;
            }

            $group = $option->getGroup();
            if ($group === null) {
                continue;
            }

            $groupId = $group->getId();

            if (isset($groups[$groupId])) {
                $group = $groups[$groupId];
            }

            $groups[$groupId] = $group;

            if (!$group->getOptions()) {
                $group->setOptions(new PropertyGroupOptionCollection());
            }

            $group->getOptions()->add($option);

            $option->setConfiguratorSetting($setting);
        }

        return $groups;
    }

    private function sortSettings(SalesChannelProductEntity $product, array $groups): PropertyGroupCollection
    {
        $sorting = $product->getConfiguratorGroupConfig() ?? [];

        $sorting = array_column($sorting, 'id');

        $sorted = [];

        foreach ($sorting as $groupId) {
            if (!isset($groups[$groupId])) {
                continue;
            }
            $sorted[$groupId] = $groups[$groupId];
        }

        foreach ($groups as $groupId => $group) {
            if (isset($sorted[$groupId])) {
                continue;
            }
            $sorted[$groupId] = $group;
        }

        foreach ($groups as $group) {
            if (!$group->getOptions()) {
                continue;
            }

            /* @var PropertyGroupEntity $group */
            $group->getOptions()->sort(
                static function (PropertyGroupOptionEntity $a, PropertyGroupOptionEntity $b) {
                    return $a->getConfiguratorSetting()->getPosition() <=> $b->getConfiguratorSetting()->getPosition();
                }
            );
        }

        return new PropertyGroupCollection($sorted);
    }

    private function isCombinable(
        PropertyGroupOptionEntity $option,
        array $current,
        AvailableCombinationResult $combinations
    ): ?bool {
        unset($current[$option->getGroupId()]);
        $current[] = $option->getId();

        // available with all other current selected options
        if ($combinations->hasCombination($current)) {
            return true;
        }

        // available but not with the other current selected options
        if ($combinations->hasOptionId($option->getId())) {
            return false;
        }

        return null;
    }

    private function buildCurrentOptions(SalesChannelProductEntity $product, PropertyGroupCollection $groups): array
    {
        $keyMap = $groups->getOptionIdMap();

        $current = [];
        foreach ($product->getOptionIds() as $optionId) {
            $groupId = $keyMap[$optionId];

            $current[$groupId] = $optionId;
        }

        return $current;
    }
}
