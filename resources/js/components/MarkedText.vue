<script>
export default {
    props: {
        parts: Array,
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
