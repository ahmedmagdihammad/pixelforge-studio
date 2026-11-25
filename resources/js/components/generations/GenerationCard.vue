<script setup lang="ts">
import StatusBadge from '@/components/generations/StatusBadge.vue';
import type { Generation } from '@/types';
import { ImageOff, Loader2 } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ generation: Generation }>();

const inProgress = computed(() => ['submitting', 'queued', 'processing'].includes(props.generation.status));

const waitingText = computed(
    () =>
        ({
            submitting: 'Sending to Pixelforge…',
            queued: 'Waiting in Pixelforge’s queue…',
            processing: 'Drawing your image…',
        })[props.generation.status as 'submitting' | 'queued' | 'processing'],
);

const aspect = computed(() => ({ '1024x1024': 'aspect-square', '1536x1024': 'aspect-[3/2]', '1024x1536': 'aspect-[2/3]' })[props.generation.size]);

const createdAt = computed(() => new Date(props.generation.created_at).toLocaleString());
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
        <div :class="['relative flex max-h-80 items-center justify-center bg-muted', aspect]">
            <img
                v-if="generation.status === 'succeeded' && generation.image_url && !generation.image_expired"
                :src="generation.image_url"
                :alt="generation.prompt"
                class="h-full w-full object-contain"
                loading="lazy"
            />
            <div v-else-if="inProgress" class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground">
                <Loader2 class="size-6 animate-spin" />
                {{ waitingText }}
            </div>
            <div v-else-if="generation.status === 'succeeded'" class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground">
                <ImageOff class="size-6" />
                Image link expired (Pixelforge keeps images for 24 hours)
            </div>
            <div v-else class="p-4 text-center text-sm text-red-700 dark:text-red-300">
                <p class="font-medium">{{ generation.error_message || 'This generation failed.' }}</p>
                <p class="mt-1 text-xs text-muted-foreground">You weren’t charged.</p>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-2 p-3 text-sm">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge :status="generation.status" />
                <span class="text-xs text-muted-foreground">{{ createdAt }}</span>
            </div>
            <p class="line-clamp-2" :title="generation.prompt">{{ generation.prompt }}</p>
            <p class="text-xs text-muted-foreground">{{ generation.size }} · {{ generation.quality }} quality · {{ generation.reference_images.length }} reference image(s)</p>

            <dl class="mt-auto grid grid-cols-2 gap-2 border-t pt-2 text-xs">
                <div>
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd class="font-mono">{{ generation.estimated_min }} – {{ generation.estimated_max }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Actual cost</dt>
                    <dd class="font-mono font-semibold">
                        <template v-if="generation.charge">{{ generation.charge }}</template>
                        <template v-else-if="generation.status === 'failed'">$0.00 (free)</template>
                        <template v-else>
                            <span class="font-normal text-muted-foreground">when it finishes</span>
                        </template>
                    </dd>
                </div>
            </dl>
        </div>
    </article>
</template>
