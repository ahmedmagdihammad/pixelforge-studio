<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface Totals {
    generations: number;
    charged: string;
    cost: string;
    profit: string;
}

const props = defineProps<{
    filters: { start_date: string; end_date: string };
    total: Totals & { pixelforge_cost: string | null; matches: boolean | null };
    days: (Totals & { date: string; pixelforge_cost: string | null; matches: boolean | null })[];
    byUser: (Totals & { user: string })[];
    byDayAndUser: (Totals & { date: string; user: string })[];
    pixelforgeError: string | null;
}>();

const filters = reactive({ ...props.filters });

function apply() {
    router.get(route('admin.report'), filters, { preserveState: true });
}
</script>

<template>
    <Head title="Admin report" />

    <AppLayout :breadcrumbs="[{ title: 'Admin report', href: '/admin/report' }]">
        <div class="flex flex-col gap-6 p-4">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Spend, provider cost and profit</h1>
                    <p class="text-sm text-muted-foreground">
                        By UTC day of completion, the same way Pixelforge bills. Provider costs are checked against Pixelforge’s
                        <code class="text-xs">GET /v1/usage</code>.
                    </p>
                </div>
                <form class="flex items-end gap-2" @submit.prevent="apply">
                    <div class="space-y-1">
                        <Label for="start">From</Label>
                        <Input id="start" v-model="filters.start_date" type="date" />
                    </div>
                    <div class="space-y-1">
                        <Label for="end">To</Label>
                        <Input id="end" v-model="filters.end_date" type="date" />
                    </div>
                    <Button type="submit">Show</Button>
                </form>
            </div>

            <p v-if="pixelforgeError" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900">{{ pixelforgeError }}</p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Credit spend (charged to users)</p>
                    <p class="font-mono text-2xl font-semibold">{{ total.charged }}</p>
                    <p class="text-xs text-muted-foreground">{{ total.generations }} succeeded generations</p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Provider cost (our records)</p>
                    <p class="font-mono text-2xl font-semibold">{{ total.cost }}</p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Pixelforge says</p>
                    <p class="font-mono text-2xl font-semibold">{{ total.pixelforge_cost ?? '–' }}</p>
                    <p v-if="total.matches !== null" :class="['text-xs font-medium', total.matches ? 'text-emerald-600' : 'text-red-600']">
                        {{ total.matches ? '✓ Matches our records exactly' : '✗ Differs from our records' }}
                    </p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Profit</p>
                    <p class="font-mono text-2xl font-semibold">{{ total.profit }}</p>
                </div>
            </div>

            <section class="space-y-2">
                <h2 class="font-semibold">Per day</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/60 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2">Date (UTC)</th>
                                <th class="px-3 py-2 text-right">Generations</th>
                                <th class="px-3 py-2 text-right">Credit spend</th>
                                <th class="px-3 py-2 text-right">Provider cost</th>
                                <th class="px-3 py-2 text-right">Pixelforge says</th>
                                <th class="px-3 py-2 text-right">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono">
                            <tr v-for="day in days" :key="day.date" :class="['border-t', day.generations === 0 && 'text-muted-foreground']">
                                <td class="px-3 py-1.5 font-sans">{{ day.date }}</td>
                                <td class="px-3 py-1.5 text-right">{{ day.generations }}</td>
                                <td class="px-3 py-1.5 text-right">{{ day.charged }}</td>
                                <td class="px-3 py-1.5 text-right">{{ day.cost }}</td>
                                <td class="px-3 py-1.5 text-right">
                                    {{ day.pixelforge_cost ?? '–' }}
                                    <span v-if="day.matches === true" class="text-emerald-600" title="Matches">✓</span>
                                    <span v-else-if="day.matches === false" class="text-red-600" title="Differs from our records">✗</span>
                                </td>
                                <td class="px-3 py-1.5 text-right">{{ day.profit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-2">
                <h2 class="font-semibold">Per user</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/60 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2">User</th>
                                <th class="px-3 py-2 text-right">Generations</th>
                                <th class="px-3 py-2 text-right">Credit spend</th>
                                <th class="px-3 py-2 text-right">Provider cost</th>
                                <th class="px-3 py-2 text-right">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono">
                            <tr v-if="byUser.length === 0">
                                <td colspan="5" class="px-3 py-4 text-center font-sans text-muted-foreground">No succeeded generations in this range.</td>
                            </tr>
                            <tr v-for="row in byUser" :key="row.user" class="border-t">
                                <td class="px-3 py-1.5 font-sans">{{ row.user }}</td>
                                <td class="px-3 py-1.5 text-right">{{ row.generations }}</td>
                                <td class="px-3 py-1.5 text-right">{{ row.charged }}</td>
                                <td class="px-3 py-1.5 text-right">{{ row.cost }}</td>
                                <td class="px-3 py-1.5 text-right">{{ row.profit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-2">
                <h2 class="font-semibold">Per day and user</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/60 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2">Date (UTC)</th>
                                <th class="px-3 py-2">User</th>
                                <th class="px-3 py-2 text-right">Generations</th>
                                <th class="px-3 py-2 text-right">Credit spend</th>
                                <th class="px-3 py-2 text-right">Provider cost</th>
                                <th class="px-3 py-2 text-right">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono">
                            <tr v-if="byDayAndUser.length === 0">
                                <td colspan="6" class="px-3 py-4 text-center font-sans text-muted-foreground">No succeeded generations in this range.</td>
                            </tr>
                            <tr v-for="row in byDayAndUser" :key="row.date + row.user" class="border-t">
                                <td class="px-3 py-1.5 font-sans">{{ row.date }}</td>
                                <td class="px-3 py-1.5 font-sans">{{ row.user }}</td>
                                <td class="px-3 py-1.5 text-right">{{ row.generations }}</td>
                                <td class="px-3 py-1.5 text-right">{{ row.charged }}</td>
                                <td class="px-3 py-1.5 text-right">{{ row.cost }}</td>
                                <td class="px-3 py-1.5 text-right">{{ row.profit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
