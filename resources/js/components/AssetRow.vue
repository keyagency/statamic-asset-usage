<script>
import { Badge, Button } from '@statamic/cms/ui'
import RowCheckbox from './RowCheckbox.vue'
import UsageList from './UsageList.vue'

export default {
    components: { Badge, Button, RowCheckbox, UsageList },

    props: {
        asset: Object,
        selects: Boolean,
        selectable: Boolean,
        selected: Boolean,
        expanded: Boolean,
        deletes: Boolean,
        showsSavings: Boolean,
        canCompress: Boolean,
        compressUrl: String,
        threshold: Number,
        indexStale: Boolean,
        showsContainer: Boolean,
        columnCount: Number,
        siteTitles: Object,
        multisite: Boolean,
    },

    emits: ['toggle-select', 'toggle-expand', 'delete'],

    computed: {
        compression() {
            return this.asset.compression
        },

        compressLink() {
            return `${this.compressUrl}?asset=${encodeURIComponent(this.asset.id)}`
        },
    },

    methods: {
        savingsLabel(savings) {
            return `−${Math.round(savings)}%`
        },
    },
}
</script>

<template>
    <tr class="border-gray-200 last:border-b-0 dark:border-gray-700" :class="{ 'border-b': !expanded }">
        <td v-if="selects" class="py-2.5 ps-4">
            <div class="flex items-center">
                <RowCheckbox
                    v-if="selectable"
                    :model-value="selected"
                    :label="asset.path"
                    @update:model-value="$emit('toggle-select')"
                />
            </div>
        </td>

        <td class="w-full px-4 py-2.5">
            <div class="flex min-w-56 items-center gap-3">
                <img v-if="asset.thumbnail" class="size-10 shrink-0 rounded object-cover" :src="asset.thumbnail" :alt="asset.basename" />
                <div
                    v-else
                    class="flex size-10 shrink-0 items-center justify-center rounded bg-gray-100 text-[10px] font-medium text-gray-500 uppercase dark:bg-gray-800 dark:text-gray-400"
                    v-text="asset.extension"
                />

                <a
                    class="font-medium break-all hover:underline"
                    :href="asset.edit_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    v-text="asset.path"
                />

                <Badge v-if="showsContainer" class="shrink-0" :text="asset.container_title" />
            </div>
        </td>

        <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-400" v-text="asset.size" />
        <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-400" v-text="asset.dimensions" />
        <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-400" v-text="asset.dpi" />
        <td v-if="showsSavings" class="px-4 py-2.5 whitespace-nowrap">
            <template v-if="compression">
                <Button
                    v-if="compression.compressible && canCompress"
                    size="sm"
                    :href="compressLink"
                    :text="__('asset-usage::messages.compress.button', { percent: Math.round(compression.savings) })"
                />
                <Badge v-else-if="compression.compressible" color="green" :text="savingsLabel(compression.savings)" />
                <Badge
                    v-else-if="compression.status === 'compressed'"
                    color="green"
                    :href="canCompress ? compressLink : null"
                    :title="canCompress ? __('asset-usage::messages.compress.view') : null"
                    :text="compression.savings
                        ? `${__('asset-usage::messages.compress.compressed_badge')} (${savingsLabel(compression.savings)})`
                        : __('asset-usage::messages.compress.compressed_badge')"
                />
                <span
                    v-else-if="compression.status === 'ok' && Math.round(compression.savings) >= 1"
                    class="text-gray-500 dark:text-gray-400"
                    :title="__('asset-usage::messages.compress.below_threshold_tooltip', { threshold })"
                    v-text="savingsLabel(compression.savings)"
                />
                <span
                    v-else-if="compression.status === 'ok'"
                    class="text-gray-500 dark:text-gray-400"
                    :title="__('asset-usage::messages.compress.no_saving_tooltip')"
                    v-text="__('asset-usage::messages.compress.no_saving')"
                />
                <Badge
                    v-else-if="compression.status === 'too_large'"
                    color="orange"
                    :text="__('asset-usage::messages.compress.too_large')"
                    :title="__('asset-usage::messages.compress.too_large_tooltip', { width: compression.width, height: compression.height })"
                />
                <Badge
                    v-else-if="compression.status === 'unsupported'"
                    :text="__('asset-usage::messages.compress.unsupported')"
                    :title="compression.reason"
                />
                <Badge
                    v-else-if="compression.status === 'error'"
                    color="red"
                    :text="__('asset-usage::messages.compress.failed')"
                    :title="compression.reason"
                />
                <span
                    v-else
                    class="text-gray-400 dark:text-gray-500"
                    :title="__('asset-usage::messages.compress.not_analyzed_tooltip')"
                    v-text="__('asset-usage::messages.compress.not_analyzed')"
                />
            </template>
        </td>
        <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-400" v-text="asset.last_modified" />

        <td class="px-4 py-2.5 whitespace-nowrap">
            <!-- Without a current index a count of 0 means "not checked", not "unused". -->
            <Badge v-if="indexStale" :text="__('asset-usage::messages.index.not_ready')" />
            <Badge v-else-if="asset.count === 0" color="orange" :text="__('asset-usage::messages.filters.unused')" />
            <Button
                v-else
                size="sm"
                variant="ghost"
                class="-ms-3"
                :icon-append="expanded ? 'chevron-up' : 'chevron-down'"
                :aria-expanded="expanded"
                :text="__n('asset-usage::messages.used_count', asset.count, { count: asset.count })"
                @click="$emit('toggle-expand')"
            />
        </td>

        <td v-if="deletes" class="px-4 py-2.5 text-end">
            <Button
                v-if="!asset.blocker"
                size="sm"
                variant="ghost"
                icon="trash"
                icon-only
                :title="__('asset-usage::messages.delete.action')"
                :aria-label="__('asset-usage::messages.delete.action')"
                @click="$emit('delete')"
            />
        </td>
    </tr>

    <tr v-if="expanded" class="border-b border-gray-200 last:border-b-0 dark:border-gray-700">
        <td v-if="selects" />
        <td :colspan="columnCount + (deletes ? 1 : 0)" class="pe-4 pb-3 ps-17">
            <UsageList :usages="asset.usages" :site-titles="siteTitles" :multisite="multisite" />
        </td>
    </tr>
</template>
