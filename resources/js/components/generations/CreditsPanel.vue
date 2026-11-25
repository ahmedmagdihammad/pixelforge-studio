<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { Credits } from '@/types';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{ credits: Credits }>();

const buying = ref<number | null>(null);

function buy(amount: number) {
    buying.value = amount;
    router.post(route('credits.store'), { amount }, { preserveScroll: true, onFinish: () => (buying.value = null) });
}
</script>

<template>
    <section class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
        <h2 class="text-sm font-medium text-muted-foreground">Credits</h2>
        <p class="mt-1 font-mono text-3xl font-semibold">{{ credits.available }}</p>
        <p class="text-xs text-muted-foreground">available to spend</p>

        <dl class="mt-3 space-y-1 text-xs">
            <div class="flex justify-between">
                <dt class="text-muted-foreground">Balance</dt>
                <dd class="font-mono">{{ credits.balance }}</dd>
            </div>
            <div class="flex justify-between" title="The most your unfinished generations can cost. Released or charged when they finish.">
                <dt class="text-muted-foreground">Held for running generations</dt>
                <dd class="font-mono">{{ credits.held }}</dd>
            </div>
        </dl>

        <div class="mt-4">
            <p class="mb-2 text-xs font-medium">Buy credits <span class="font-normal text-muted-foreground">(demo, no payment taken)</span></p>
            <div class="grid grid-cols-3 gap-2">
                <Button v-for="amount in [5, 10, 25]" :key="amount" variant="outline" size="sm" :disabled="buying !== null" @click="buy(amount)">
                    +${{ amount }}
                </Button>
            </div>
        </div>
    </section>
</template>
