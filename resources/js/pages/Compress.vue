<script>
import { Head, router } from '@statamic/cms/inertia'
import { Alert, Button, ButtonGroup, Card, ConfirmationModal, Header, Icon, Text } from '@statamic/cms/ui'
import CompareSideBySide from '../components/CompareSideBySide.vue'
import CompareSlider from '../components/CompareSlider.vue'
import { escapeHtml, formatDate, mark, parts } from '../support/formatting.js'

/** Behind transparent PNGs, so black logos stay visible. */
const BACKGROUNDS = {
    light: '#f4f4f4',
    dark: '#1e1e1e',
    checker: 'repeating-conic-gradient(#cfcfcf 0 25%, #fff 0 50%) 0 0 / 20px 20px',
}

export default {
    components: { Alert, Button, ButtonGroup, Card, CompareSideBySide, CompareSlider, ConfirmationModal, Head, Header, Icon, Text },

    props: {
        icon: String,
        asset: Object,
        settings: Object,
        threshold: Number,
        pngquantUrl: String,
        keepOriginalsDays: Number,
        backup: Object,
        beforeUrl: String,
        afterUrl: String,
        compressUrl: String,
        restoreUrl: String,
        backUrl: String,
        previewUrl: String,
    },

    data() {
        return {
            mode: 'slider',
            zoom: 'fit',
            background: 'light',
            confirming: false,
            confirmingRestore: false,
            working: false,
            /** The analysis of the preview, null until the POST below has made it. */
            record: null,
            sizes: null,
            previewFailed: false,
        }
    },

    /** Made here rather than when the page opens, so a plain GET never sets the compressor to work. */
    mounted() {
        this.$axios
            .post(this.previewUrl, { asset: this.asset.id })
            .then(response => {
                this.record = response.data.record
                this.sizes = response.data.sizes
            })
            .catch(() => (this.previewFailed = true))
    },

    computed: {
        ok() {
            return this.record?.status === 'ok'
        },

        savings() {
            return Math.round(this.record?.savings ?? 0)
        },

        canCompress() {
            return this.ok && (this.record.savings ?? 0) > 0
        },

        modeOptions() {
            return [
                { value: 'slider', label: __('asset-usage::messages.compress.mode_slider') },
                { value: 'side_by_side', label: __('asset-usage::messages.compress.mode_side_by_side') },
            ]
        },

        zoomOptions() {
            return [
                { value: 'fit', label: __('asset-usage::messages.compress.fit') },
                { value: 1, label: __('asset-usage::messages.compress.actual_size') },
                { value: 2, label: __('asset-usage::messages.compress.zoom_double') },
            ]
        },

        /** The numbers for each side, under the slider. */
        sides() {
            return [
                {
                    key: 'before',
                    label: __('asset-usage::messages.compress.before'),
                    size: this.sizes.before,
                    width: this.record.before_width,
                    height: this.record.before_height,
                    dpi: this.record.before_dpi,
                },
                {
                    key: 'after',
                    label: __('asset-usage::messages.compress.after'),
                    size: this.savings > 0 ? `${this.sizes.after} (−${this.savings}%)` : this.sizes.after,
                    width: this.record.after_width,
                    height: this.record.after_height,
                    dpi: this.record.after_dpi,
                },
            ]
        },

        backgroundStyle() {
            return BACKGROUNDS[this.background]
        },

        failureText() {
            if (this.record.status === 'compressed') {
                return __('asset-usage::messages.compress.already_compressed')
            }

            if (this.record.status === 'too_large') {
                return __('asset-usage::messages.compress.too_large_tooltip', {
                    width: this.record.before_width,
                    height: this.record.before_height,
                })
            }

            return __('asset-usage::messages.compress.cannot_compress', { reason: this.record.reason ?? '' })
        },

        /** The settings the preview was made with, marked so they stand out. */
        previewParts() {
            return parts(__('asset-usage::messages.compress.preview_info', {
                max: mark(this.settings.max_dimension),
                dpi: mark(this.settings.dpi),
                jpg: mark(this.settings.jpg_quality),
                webp: mark(this.settings.webp_quality),
            }))
        },

        /** Only for a PNG on a server without pngquant, where installing it would help. */
        showsPngquantLink() {
            return this.asset.extension === 'png' && !this.settings.pngquant
        },

        /** The date a backup made today would be kept until, marked to stand out. */
        keepParts() {
            if (this.keepOriginalsDays === null || this.keepOriginalsDays === undefined) {
                return parts(__('asset-usage::messages.compress.keep_forever'))
            }

            const date = new Date(Date.now() + this.keepOriginalsDays * 86400000)

            return parts(__('asset-usage::messages.compress.keep_info', { date: mark(this.formatDate(date.toISOString())) }))
        },

        /** What the earlier compression saved, against the kept original. */
        compressedInfo() {
            const compressed = this.backup.compressed

            return compressed
                ? parts(__('asset-usage::messages.compress.editor_compressed', {
                    before: compressed.before,
                    after: compressed.after,
                    saving: mark(`−${compressed.savings}%`),
                }))
                : []
        },

        restoreInfo() {
            if (!this.backup.expires_at) return __('asset-usage::messages.compress.restore_info_forever')

            return __('asset-usage::messages.compress.restore_info', { date: this.formatDate(this.backup.expires_at) })
        },
    },

    methods: {
        formatDate(date) {
            return formatDate(date, { preset: 'date', month: 'long' })
        },

        describe(side) {
            return [side.size, side.width ? `${side.width} × ${side.height}` : null, side.dpi ? `${side.dpi} DPI` : null]
                .filter(Boolean)
                .join(' · ')
        },

        compress() {
            this.send(this.compressUrl, { asset: this.asset.id, version: this.asset.version })
        },

        restore() {
            this.send(this.restoreUrl, { asset: this.asset.id })
        },

        /** The server leaves its message as a toast for the page the redirect leads to. */
        send(url, data) {
            this.working = true

            this.$axios
                .post(url, data)
                .then(response => router.visit(response.data.redirect))
                .catch(error => {
                    this.$toast.error(escapeHtml(error.response?.data?.message ?? error.message))
                    this.working = false
                })
                .finally(() => {
                    this.confirming = false
                    this.confirmingRestore = false
                })
        },
    },
}
</script>

<template>
    <div>
        <Head :title="__('asset-usage::messages.compress.title')" />

        <!-- The action sits where Statamic puts its own, next to the title, rather than below the comparison. -->
        <Header :title="__('asset-usage::messages.compress.title')" :icon="icon">
            <Button :href="backUrl" icon="arrow-left" :text="__('asset-usage::messages.compress.back')" />
            <Button
                v-if="canCompress"
                variant="primary"
                :disabled="working"
                :text="__('asset-usage::messages.compress.compress')"
                @click="confirming = true"
            />
        </Header>

        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            <a class="font-medium hover:underline" :href="asset.edit_url" target="_blank" rel="noopener noreferrer" v-text="asset.path" />
            <span class="mx-1" aria-hidden="true">·</span>
            <span v-text="asset.container_title" />
        </p>

        <Alert v-if="previewFailed" class="mb-4" variant="warning" :text="__('asset-usage::messages.compress.preview_failed')" />

        <Card v-else-if="!record" class="mb-4">
            <Text as="p" size="sm" variant="subtle" :text="__('asset-usage::messages.compress.preparing')" />
        </Card>

        <Alert v-else-if="!ok" class="mb-4" :variant="record.status === 'compressed' ? 'default' : 'warning'" :text="failureText" />

        <template v-else>
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p
                    v-if="savings > 0"
                    class="text-2xl font-semibold text-green-700 dark:text-green-400"
                    v-text="__('asset-usage::messages.compress.saving', { percent: savings })"
                />

                <div class="flex flex-wrap items-center gap-2 sm:ms-auto">
                    <ButtonGroup role="group" :aria-label="__('asset-usage::messages.compress.mode_label')">
                        <Button
                            v-for="option in modeOptions"
                            :key="option.value"
                            size="sm"
                            :variant="mode === option.value ? 'primary' : 'default'"
                            :aria-pressed="String(mode === option.value)"
                            :text="option.label"
                            @click="mode = option.value"
                        />
                    </ButtonGroup>

                    <ButtonGroup role="group" :aria-label="__('asset-usage::messages.compress.zoom_label')">
                        <Button
                            v-for="option in zoomOptions"
                            :key="option.value"
                            size="sm"
                            :variant="zoom === option.value ? 'primary' : 'default'"
                            :aria-pressed="String(zoom === option.value)"
                            :text="option.label"
                            @click="zoom = option.value"
                        />
                    </ButtonGroup>

                    <ButtonGroup role="group" :aria-label="__('asset-usage::messages.compress.background')">
                        <Button
                            v-for="option in ['light', 'dark', 'checker']"
                            :key="option"
                            size="sm"
                            :variant="background === option ? 'primary' : 'default'"
                            :aria-pressed="String(background === option)"
                            :text="__(`asset-usage::messages.compress.background_${option}`)"
                            @click="background = option"
                        />
                    </ButtonGroup>
                </div>
            </div>

            <Alert
                v-if="(record.savings ?? 0) <= 0"
                class="mb-4"
                variant="warning"
                :text="__('asset-usage::messages.compress.growth', { percent: Math.abs(savings) })"
            />
            <Alert
                v-else-if="record.savings < threshold"
                class="mb-4"
                :text="__('asset-usage::messages.compress.below_threshold', { percent: savings, threshold })"
            />
            <Alert
                v-if="record.icc_lost"
                class="mb-4"
                variant="warning"
                :text="__('asset-usage::messages.compress.icc_warning')"
            />

            <Card inset class="mb-4 overflow-hidden">
                <CompareSideBySide
                    v-if="mode === 'side_by_side'"
                    :before="beforeUrl"
                    :after="afterUrl"
                    :width="record.after_width || record.before_width"
                    :height="record.after_height || record.before_height"
                    :zoom="zoom"
                    :background="backgroundStyle"
                    :before-label="__('asset-usage::messages.compress.before')"
                    :after-label="__('asset-usage::messages.compress.after')"
                />
                <CompareSlider
                    v-else
                    :before="beforeUrl"
                    :after="afterUrl"
                    :width="record.after_width || record.before_width"
                    :height="record.after_height || record.before_height"
                    :zoom="zoom"
                    :background="backgroundStyle"
                    :before-label="__('asset-usage::messages.compress.before')"
                    :after-label="__('asset-usage::messages.compress.after')"
                    :label="__('asset-usage::messages.compress.compare_label')"
                />

                <div class="grid gap-2 border-t border-gray-200 px-4 py-2.5 text-sm tabular-nums sm:grid-cols-2 dark:border-gray-700">
                    <p v-for="side in sides" :key="side.key" :class="{ 'sm:text-end': side.key === 'after' }">
                        <span class="font-medium" v-text="side.label" />
                        <span class="text-gray-600 dark:text-gray-400" v-text="` · ${describe(side)}`" />
                    </p>
                </div>
            </Card>

            <ul class="mb-6 space-y-2 text-sm">
                <li class="flex gap-2 text-gray-600 dark:text-gray-400">
                    <Icon name="info" class="mt-0.5 size-4 shrink-0" />
                    <span>
                        <template v-for="(part, i) in previewParts" :key="i">
                            <strong v-if="part.marked" class="font-semibold text-gray-900 dark:text-gray-100" v-text="part.text" />
                            <template v-else>{{ part.text }}</template>
                        </template>
                    </span>
                </li>
                <li v-if="asset.extension === 'png' && settings.pngquant" class="flex gap-2 text-gray-600 dark:text-gray-400">
                    <Icon name="info" class="mt-0.5 size-4 shrink-0" />
                    <span v-text="__('asset-usage::messages.compress.pngquant_on')" />
                </li>
                <li v-if="showsPngquantLink" class="flex gap-2 text-amber-700 dark:text-amber-400">
                    <Icon name="alert-warning-exclamation-mark" class="mt-0.5 size-4 shrink-0" />
                    <span>
                        {{ __('asset-usage::messages.compress.pngquant_off') }}
                        <a
                            class="mt-0.5 block w-fit font-medium underline hover:no-underline"
                            :href="pngquantUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            v-text="__('asset-usage::messages.compress.pngquant_link')"
                        />
                    </span>
                </li>
                <li v-if="canCompress" class="flex gap-2 text-gray-600 dark:text-gray-400">
                    <Icon name="time-clock" class="mt-0.5 size-4 shrink-0" />
                    <span>
                        <template v-for="(part, i) in keepParts" :key="i">
                            <strong v-if="part.marked" class="font-semibold text-gray-900 dark:text-gray-100" v-text="part.text" />
                            <template v-else>{{ part.text }}</template>
                        </template>
                    </span>
                </li>
            </ul>
        </template>

        <Card v-if="backup.exists" class="mb-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-0.5">
                    <p v-if="compressedInfo.length" class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        <template v-for="(part, i) in compressedInfo" :key="i">
                            <span v-if="part.marked" class="text-green-700 dark:text-green-400" v-text="part.text" />
                            <template v-else>{{ part.text }}</template>
                        </template>
                    </p>
                    <Text as="p" size="sm" variant="subtle" :text="restoreInfo" />
                </div>
                <!-- Primary, unless Compress already is: one primary action per page. -->
                <Button
                    class="shrink-0"
                    :variant="canCompress ? 'default' : 'primary'"
                    :disabled="working"
                    :text="__('asset-usage::messages.compress.restore')"
                    @click="confirmingRestore = true"
                />
            </div>
        </Card>

        <ConfirmationModal
            :open="confirming"
            :title="__('asset-usage::messages.compress.confirm_title')"
            :button-text="__('asset-usage::messages.compress.compress')"
            :busy="working"
            @confirm="compress"
            @update:open="open => (confirming = open)"
        >
            <p class="mb-2 text-gray-700 antialiased dark:text-gray-200" v-text="__('asset-usage::messages.compress.confirm')" />
            <pre
                class="overflow-auto rounded bg-gray-100 p-2 text-xs dark:bg-gray-800"
                v-text="__('asset-usage::messages.compress.confirm_file', { file: asset.basename, before: sizes.before, after: sizes.after })"
            />
        </ConfirmationModal>

        <ConfirmationModal
            :open="confirmingRestore"
            :title="__('asset-usage::messages.compress.restore_confirm_title')"
            :button-text="__('asset-usage::messages.compress.restore')"
            :busy="working"
            @confirm="restore"
            @update:open="open => (confirmingRestore = open)"
        >
            <p class="mb-2 text-gray-700 antialiased dark:text-gray-200" v-text="__('asset-usage::messages.compress.restore_confirm')" />
            <pre class="overflow-auto rounded bg-gray-100 p-2 text-xs dark:bg-gray-800" v-text="asset.basename" />
        </ConfirmationModal>
    </div>
</template>
