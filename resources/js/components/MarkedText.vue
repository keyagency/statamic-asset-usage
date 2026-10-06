<script>
/**
 * A translated sentence from parts() in support/formatting.js, with the values
 * passed through mark() set apart in their own element, without v-html.
 */
export default {
    props: {
        parts: Array,
        /** The element a marked value is rendered in. */
        as: { type: String, default: 'strong' },
        markedClass: String,
    },
}
</script>

<template>
    <span>
        <template v-for="(part, i) in parts" :key="i">
            <component :is="as" v-if="part.marked" :class="markedClass" v-text="part.text" />
            <template v-else>{{ part.text }}</template>
        </template>
    </span>
</template>
