<script setup lang="ts">
import type { GenerationStatus } from '@/types';
import { CheckCircle2, Clock, Loader2, Send, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ status: GenerationStatus }>();

const styles = {
    submitting: { label: 'Sending', icon: Send, class: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200', spin: false },
    queued: { label: 'Queued', icon: Clock, class: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200', spin: false },
    processing: { label: 'Processing', icon: Loader2, class: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200', spin: true },
    succeeded: {
        label: 'Succeeded',
        icon: CheckCircle2,
        class: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        spin: false,
    },
    failed: { label: 'Failed', icon: XCircle, class: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200', spin: false },
} as const;

const style = computed(() => styles[props.status]);
</script>

<template>
    <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', style.class]">
        <component :is="style.icon" :class="['size-3.5', style.spin && 'animate-spin']" />
        {{ style.label }}
    </span>
</template>
