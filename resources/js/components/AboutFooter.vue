<script>
import { Text } from '@statamic/cms/ui'

/** What the overview can't see, the disclaimer, and who made the addon. */
export default {
    components: { Text },
}
</script>

<template>
    <div class="mt-6 border-t border-gray-200 pt-4 text-center dark:border-gray-700">
        <Text
            as="p"
            class="mx-auto max-w-2xl"
            size="sm"
            variant="subtle"
            :text="__('asset-usage::messages.not_scanned')"
        />

        <Text
            as="p"
            class="mx-auto mt-2 max-w-2xl"
            size="sm"
            variant="subtle"
            :text="__('asset-usage::messages.disclaimer')"
        />

        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ __('asset-usage::messages.made_by') }}
            <a
                class="text-blue-600 hover:underline dark:text-blue-400"
                href="https://statamic.com/creators/key-agency"
                target="_blank"
                rel="noopener"
            >Key Agency</a>
            <span class="mx-1" aria-hidden="true">·</span>
            <a
                class="text-blue-600 hover:underline dark:text-blue-400"
                href="https://github.com/keyagency"
                target="_blank"
                rel="noopener"
            >GitHub</a>
            <span class="mx-1" aria-hidden="true">·</span>
            <a
                class="text-blue-600 hover:underline dark:text-blue-400"
                href="https://statamic.com/addons/key-agency/asset-usage"
                target="_blank"
                rel="noopener"
            >Statamic Marketplace</a>
        </p>
    </div>
</template>
