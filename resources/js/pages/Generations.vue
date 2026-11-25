<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import CreditsPanel from '@/components/generations/CreditsPanel.vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Generation, Paginated, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{
    generations: Paginated<Generation>;
    sizes: string[];
    qualities: string[];
}>();

const page = usePage<SharedData>();
const credits = computed(() => page.props.credits!);

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    referenceImagesText: '',
});

const referenceImages = computed(() =>
    form.referenceImagesText
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean),
);

// ---- Live price estimate (calculated by the server, the same code that holds the credits) ----

interface Estimate {
    min: string;
    max: string;
    available: string;
    affordable: boolean;
}

const estimate = ref<Estimate | null>(null);
const estimateError = ref<string | null>(null);

function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
}

const refreshEstimate = useDebounceFn(async () => {
    const response = await fetch(route('generations.estimate'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify({ prompt: form.prompt, size: form.size, quality: form.quality, reference_images: referenceImages.value }),
    });
    const body = await response.json();

    if (response.ok) {
        estimate.value = body;
        estimateError.value = null;
    } else {
        estimate.value = null;
        estimateError.value = (Object.values(body.errors ?? {}).flat()[0] as string) ?? 'Couldn’t estimate the price.';
    }
}, 300);

watch(() => [form.prompt, form.size, form.quality, referenceImages.value.join('\n'), credits.value.available], refreshEstimate, {
    immediate: true,
});

function submit() {
    form.transform((data) => ({
        prompt: data.prompt,
        size: data.size,
        quality: data.quality,
        reference_images: referenceImages.value,
    })).post(route('generations.store'), { preserveScroll: true });
}

// ---- Live status: poll while anything on this page is still running ----

const hasRunning = computed(() => props.generations.data.some((g) => ['submitting', 'queued', 'processing'].includes(g.status)));
let timer: ReturnType<typeof setInterval> | undefined;

watch(
    hasRunning,
    (running) => {
        clearInterval(timer);
        if (running) {
            timer = setInterval(() => router.reload({ only: ['generations', 'credits'] }), 3000);
        }
    },
    { immediate: true },
);

onUnmounted(() => clearInterval(timer));

// Server errors are keyed by the request field (reference_images, reference_images.0, ...), not the form field.
const referenceImagesError = computed(() => {
    const errors = form.errors as Record<string, string>;
    return errors.reference_images ?? Object.entries(errors).find(([key]) => key.startsWith('reference_images.'))?.[1];
});

const promptLength = computed(() => [...form.prompt].length); // characters, not UTF-16 units
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="[{ title: 'Generate', href: '/generations' }]">
        <div class="flex flex-col gap-6 p-4">
            <div
                v-if="page.props.flash.success"
                class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
            >
                {{ page.props.flash.success }}
            </div>

            <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
                <form class="space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border" @submit.prevent="submit">
                    <div class="space-y-1.5">
                        <div class="flex items-baseline justify-between">
                            <Label for="prompt">Prompt</Label>
                            <span class="text-xs text-muted-foreground">{{ promptLength }} / 4000</span>
                        </div>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="4"
                            maxlength="4000"
                            required
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <InputError :message="form.errors.prompt" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <fieldset class="space-y-1.5">
                            <legend class="text-sm font-medium">Size</legend>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    v-for="size in sizes"
                                    :key="size"
                                    :class="[
                                        'cursor-pointer rounded-md border px-3 py-1.5 text-sm',
                                        form.size === size ? 'border-primary bg-primary text-primary-foreground' : 'border-input hover:bg-accent',
                                    ]"
                                >
                                    <input v-model="form.size" type="radio" :value="size" class="sr-only" />
                                    {{ size }}
                                </label>
                            </div>
                            <InputError :message="form.errors.size" />
                        </fieldset>

                        <fieldset class="space-y-1.5">
                            <legend class="text-sm font-medium">Quality</legend>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    v-for="quality in qualities"
                                    :key="quality"
                                    :class="[
                                        'cursor-pointer rounded-md border px-3 py-1.5 text-sm capitalize',
                                        form.quality === quality
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-input hover:bg-accent',
                                    ]"
                                >
                                    <input v-model="form.quality" type="radio" :value="quality" class="sr-only" />
                                    {{ quality }}
                                </label>
                            </div>
                            <InputError :message="form.errors.quality" />
                        </fieldset>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="references"
                            >Reference image URLs <span class="font-normal text-muted-foreground">(optional, one per line, up to 4)</span></Label
                        >
                        <textarea
                            id="references"
                            v-model="form.referenceImagesText"
                            rows="2"
                            placeholder="https://example.com/style.png"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <InputError :message="referenceImagesError" />
                    </div>

                    <div class="flex flex-col gap-3 rounded-lg bg-muted/60 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="text-sm">
                            <template v-if="estimate">
                                <p>
                                    Estimated cost
                                    <span class="font-mono font-semibold">{{ estimate.min }} – {{ estimate.max }}</span>
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    You pay the exact cost once it finishes, never more than {{ estimate.max }}. Failed generations are free.
                                </p>
                                <p v-if="!estimate.affordable" class="mt-1 text-xs font-medium text-red-600">
                                    You have {{ estimate.available }} available. Buy credits to generate this.
                                </p>
                            </template>
                            <p v-else-if="estimateError" class="text-xs text-red-600">{{ estimateError }}</p>
                            <p v-else class="text-xs text-muted-foreground">Working out the price…</p>
                        </div>
                        <Button type="submit" :disabled="form.processing || !form.prompt.trim() || (estimate !== null && !estimate.affordable)">
                            Generate
                        </Button>
                    </div>
                    <InputError :message="(form.errors as Record<string, string>).credits" />
                </form>

                <CreditsPanel :credits="credits" />
            </div>

            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Your generations</h2>

                <p v-if="generations.data.length === 0" class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    Nothing yet. Write a prompt above to generate your first image.
                </p>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <GenerationCard v-for="generation in generations.data" :key="generation.id" :generation="generation" />
                </div>

                <nav v-if="generations.last_page > 1" class="flex flex-wrap justify-center gap-1">
                    <template v-for="link in generations.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            :class="['rounded-md px-3 py-1 text-sm', link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-accent']"
                        >
                            <span v-html="link.label" />
                        </Link>
                        <span v-else class="px-3 py-1 text-sm text-muted-foreground" v-html="link.label" />
                    </template>
                </nav>
            </section>
        </div>
    </AppLayout>
</template>
