<script>
import { FieldtypeMixin } from '@statamic/cms'
import { Badge, Button, Heading, Text } from '@statamic/cms/ui'
import UsageList from '../components/UsageList.vue'
import { formatDate, mark, parts } from '../support/formatting.js'

/**
 * The "Used in" panel in the asset editor. Everything it shows comes from field
 * meta, resolved server-side in Fieldtypes\AssetUsage::preload(), so there is no
 * value to edit here, so the field never emits an update.
 *
 * The list sits behind a toggle: an asset used in dozens of places would
 * otherwise push the rest of the form off the screen.
 */
export default {
    mixins: [FieldtypeMixin],

    components: { Badge, Button, Heading, Text, UsageList },

    data() {
        return {
            expanded: false,
        }
    },

    computed: {
        /** Config can switch the panel off while keeping the browser column. */
        visible() {
            return this.config.panel !== false
        },

        usages() {
            return this.meta.usages ?? []
        },

        count() {
            return this.meta.count ?? 0
        },

        summary() {
            return __n('asset-usage::messages.used_count', this.count, { count: this.count })
        },

        /** Set server-side only when there is something to offer this user. */
        compression() {
            return this.meta.compression ?? null
        },

        compressionText() {
            const compression = this.compression

            if (compression.compressible) {
                return __('asset-usage::messages.compress.editor_savings', {
                    percent: compression.savings,
                    before: compression.before,
                    after: compression.after,
                })
            }

            return this.restoreText
        },

        /** For an image compressed before: what that saved, against the kept original. */
        compressedText() {
            const compressed = this.compression.compressed

            return compressed
                ? parts(__('asset-usage::messages.compress.editor_compressed', {
                    before: compressed.before,
                    after: compressed.after,
                    saving: mark(`−${compressed.savings}%`),
                }))
                : []
        },

        restoreText() {
            const compression = this.compression

            if (!compression.expires_at) return __('asset-usage::messages.compress.restore_info_forever')

            return __('asset-usage::messages.compress.restore_info', {
                date: formatDate(compression.expires_at, { preset: 'date', month: 'long' }),
            })
        },
    },
}
</script>

<template>
    <div v-if="visible">
        <template v-if="meta.building">
            <Text size="sm" variant="subtle" :text="__('asset-usage::messages.index.checking')" />
        </template>

        <template v-else-if="!meta.indexed">
            <Text size="sm" variant="subtle" :text="__('asset-usage::messages.index.not_ready_instructions')" />
            <Button
                class="mt-2"
                size="sm"
                :href="meta.toolsUrl"
                :text="__('asset-usage::messages.view_overview')"
            />
        </template>

        <template v-else-if="count === 0">
            <Badge color="orange" :text="__('asset-usage::messages.unused')" />
        </template>

        <template v-else>
            <Button
                size="sm"
                :icon-append="expanded ? 'chevron-up' : 'chevron-down'"
                :aria-expanded="expanded"
                :text="summary"
                @click="expanded = !expanded"
            />

            <UsageList
                v-if="expanded"
                class="mt-3"
                :usages="usages"
                :site-titles="meta.siteTitles"
                :multisite="meta.multisite"
            />
        </template>

        <div v-if="compression" class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
            <Heading class="mb-2" :text="__('asset-usage::messages.compress.label')" />

            <div class="flex flex-col items-start gap-2">
                <div class="space-y-0.5">
                    <p v-if="!compression.compressible && compressedText.length" class="text-sm text-gray-900 dark:text-gray-50">
                        <template v-for="(part, i) in compressedText" :key="i">
                            <span v-if="part.marked" class="font-medium text-green-700 dark:text-green-400" v-text="part.text" />
                            <template v-else>{{ part.text }}</template>
                        </template>
                    </p>
                    <Text as="p" size="sm" :variant="compression.compressible ? 'default' : 'subtle'" :text="compressionText" />
                </div>
                <Button
                    size="sm"
                    :href="compression.url"
                    :text="compression.compressible ? __('asset-usage::messages.compress.view_preview') : __('asset-usage::messages.compress.view')"
                />
            </div>
        </div>
    </div>
</template>
