<script>
import { Button } from '@statamic/cms/ui'
import RowCheckbox from './RowCheckbox.vue'

/** The list's column headings, each sorting the list, and the checkbox that selects every row it can. */
export default {
    components: { Button, RowCheckbox },

    props: {
        columns: Array,
        sortColumn: String,
        sortDirection: String,
        /** Whether the list has a checkbox column at all. */
        selects: Boolean,
        /** Whether any row on the page can be selected. */
        selectable: Boolean,
        allSelected: Boolean,
        someSelected: Boolean,
        compresses: Boolean,
        deletes: Boolean,
    },

    emits: ['sort', 'toggle-all'],

    methods: {
        sortIcon(field) {
            if (this.sortColumn !== field) return null

            return this.sortDirection === 'asc' ? 'sort-asc' : 'sort-desc'
        },

        ariaSort(field) {
            if (this.sortColumn !== field) return 'none'

            return this.sortDirection === 'asc' ? 'ascending' : 'descending'
        },
    },
}
</script>

<template>
    <thead>
        <tr class="border-b border-gray-200 text-start dark:border-gray-700">
            <th v-if="selects" scope="col" class="w-px py-2.5 ps-4">
                <!-- A block-level flex box, because an inline checkbox sits on the text baseline instead of the middle. -->
                <div class="flex items-center">
                    <RowCheckbox
                        v-if="selectable"
                        :model-value="allSelected"
                        :indeterminate="someSelected"
                        :label="compresses ? __('asset-usage::messages.compress.select_all') : __('asset-usage::messages.delete.select_all')"
                        @update:model-value="$emit('toggle-all')"
                    />
                </div>
            </th>
            <th
                v-for="column in columns"
                :key="column.field"
                scope="col"
                class="px-4 py-2.5 text-start whitespace-nowrap"
                :aria-sort="ariaSort(column.field)"
            >
                <Button
                    :text="column.label"
                    :icon-append="sortIcon(column.field)"
                    size="sm"
                    variant="ghost"
                    class="-my-1 -ms-3 text-sm! font-medium! text-gray-900! dark:text-gray-400!"
                    @click="$emit('sort', column.field)"
                />
            </th>
            <th v-if="deletes" scope="col" class="px-4 py-2.5">
                <span class="sr-only" v-text="__('asset-usage::messages.delete.action')" />
            </th>
        </tr>
    </thead>
</template>
