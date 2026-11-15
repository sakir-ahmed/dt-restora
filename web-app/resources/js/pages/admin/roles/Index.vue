<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import FormField from '@/components/ui-kit/FormField.vue';
import PageHeader from '@/components/ui-kit/PageHeader.vue';
import SlideOver from '@/components/ui-kit/SlideOver.vue';
import SwitchField from '@/components/ui-kit/SwitchField.vue';
import { useConfirm } from '@/components/ui-kit/useConfirm.js';
import { localDigits } from '@/lib/format';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';

const props = defineProps({
    roles: { type: Array, required: true },
    groups: { type: Object, required: true },
    required: { type: String, required: true },
    defaults: { type: Object, required: true },
});

const confirm = useConfirm();

const selectedName = ref(props.roles.find((role) => role.name === 'branch-manager')?.name ?? props.roles[0]?.name ?? null);
const selected = computed(() => props.roles.find((role) => role.name === selectedName.value) ?? null);

// What is ticked on screen, kept apart from what is saved until the person presses Save.
const draft = ref([]);
const draftAllBranches = ref(false);
const query = ref('');

function load() {
    draft.value = [...(selected.value?.permissions ?? [])];
    draftAllBranches.value = Boolean(selected.value?.all_branches);
}

watch(() => [selectedName.value, props.roles], load, { immediate: true });

const locked = computed(() => Boolean(selected.value?.is_locked));
const allPermissions = computed(() => Object.values(props.groups).flat());
const dirty = computed(() => {
    if (! selected.value) {
        return false;
    }

    const saved = [...selected.value.permissions].sort().join('|');

    return saved !== [...draft.value].sort().join('|') || draftAllBranches.value !== Boolean(selected.value.all_branches);
});

function has(permission) {
    return locked.value || draft.value.includes(permission);
}

function toggle(permission) {
    if (locked.value || permission === props.required) {
        return;
    }

    draft.value = draft.value.includes(permission)
        ? draft.value.filter((name) => name !== permission)
        : [...draft.value, permission];
}

function groupCount(group) {
    const names = props.groups[group];

    return names.filter((name) => has(name)).length;
}

function toggleGroup(group) {
    if (locked.value) {
        return;
    }

    const names = props.groups[group].filter((name) => name !== props.required);
    const allOn = names.every((name) => draft.value.includes(name));

    draft.value = allOn
        ? draft.value.filter((name) => ! names.includes(name))
        : [...new Set([...draft.value, ...names])];
}

function selectEverything() {
    draft.value = [...allPermissions.value];
}

function clearEverything() {
    draft.value = [props.required];
}

const filteredGroups = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return Object.entries(props.groups)
        .map(([group, names]) => ({
            group,
            names: names.filter((name) => ! needle
                || t('permissions.items.' + name).toLowerCase().includes(needle)
                || name.includes(needle)
                || t('permissions.groups.' + group).toLowerCase().includes(needle)),
        }))
        .filter((entry) => entry.names.length > 0);
});

function usersText(role) {
    if (role.users_count === 0) {
        return t('roles.no_users');
    }

    return role.users_count === 1 ? t('roles.one_user') : t('roles.users_count', { count: localDigits(role.users_count) });
}

const saving = ref(false);

function save() {
    saving.value = true;
    router.put(route('admin.roles.update', selected.value.id), {
        permissions: draft.value,
        all_branches: draftAllBranches.value,
        label: selected.value.label_en,
        label_bn: selected.value.label_bn,
        description: selected.value.description,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            if (page.props.flash?.error) {
                notify.error(String(page.props.flash.error));
            }
        },
        onError: (errors) => notify.error(String(Object.values(errors)[0] ?? t('common.error_occurred'))),
        onFinish: () => (saving.value = false),
    });
}

async function resetRole() {
    const result = await confirm({
        variant: 'warning',
        level: 'simple',
        title: t('roles.reset_role_title', { role: selected.value.label }),
        message: t('roles.reset_role_message'),
        confirmText: t('roles.reset_role'),
    });

    if (result.confirmed) {
        router.post(route('admin.roles.reset', selected.value.id), {}, { preserveScroll: true, preserveState: true });
    }
}

async function resetEverything() {
    const result = await confirm({
        variant: 'warning',
        level: 'simple',
        title: t('roles.reset_all_title'),
        message: t('roles.reset_all_message'),
        confirmText: t('roles.reset_all'),
    });

    if (result.confirmed) {
        router.post(route('admin.roles.reset-all'), {}, { preserveScroll: true, preserveState: true });
    }
}

async function removeRole() {
    const result = await confirm({
        variant: 'danger',
        level: 'simple',
        title: t('roles.delete_title', { role: selected.value.label }),
        message: t('roles.delete_message'),
        confirmText: t('roles.delete_role'),
    });

    if (result.confirmed) {
        router.delete(route('admin.roles.destroy', selected.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                selectedName.value = props.roles[0]?.name ?? null;
            },
        });
    }
}

// New role
const createOpen = ref(false);
const createForm = useForm({ label: '', label_bn: '', description: '', all_branches: false, copy_from: '' });

function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    createOpen.value = true;
}

function create() {
    createForm.transform((data) => ({ ...data, copy_from: data.copy_from || null })).post(route('admin.roles.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createOpen.value = false;
            const made = props.roles.at(-1);

            if (made) {
                selectedName.value = made.name;
            }
        },
    });
}
</script>

<script>
import AdminLayout from '@/layouts/AdminLayout.vue';

export default { layout: AdminLayout };
</script>

<template>
    <div>
        <PageHeader :title="t('roles.title')" :description="t('roles.subtitle')">
            <template #actions>
                <button type="button" class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] text-foreground hover:bg-background" @click="resetEverything">
                    <AppIcon name="rotate" size="12" />
                    {{ t('roles.reset_all') }}
                </button>
                <button type="button" class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-medium text-primary-foreground hover:opacity-90" @click="openCreate">
                    <AppIcon name="plus" size="12" />
                    {{ t('roles.new_role') }}
                </button>
            </template>
        </PageHeader>

        <div class="grid items-start gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <!-- Roles -->
            <ul class="thin-scrollbar max-h-[calc(100vh-11rem)] space-y-1.5 overflow-y-auto pr-1" :aria-label="t('roles.title')">
                <li v-for="role in roles" :key="role.id">
                    <button
                        type="button"
                        class="w-full rounded-lg border px-3 py-2 text-left transition-colors"
                        :class="role.name === selectedName ? 'border-accent bg-accent/10' : 'border-border bg-surface hover:border-accent/50'"
                        :aria-current="role.name === selectedName"
                        @click="selectedName = role.name"
                    >
                        <span class="flex items-center justify-between gap-2">
                            <span class="truncate text-[13px] font-semibold text-foreground">{{ role.label }}</span>
                            <span class="shrink-0 text-[11px] tabular-nums text-muted">{{ usersText(role) }}</span>
                        </span>
                        <span class="mt-1 flex flex-wrap items-center gap-1">
                            <span v-if="role.is_locked" class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary"><AppIcon name="lock" size="9" />{{ t('roles.locked') }}</span>
                            <span v-else-if="role.is_default" class="rounded-full bg-muted/10 px-1.5 py-0.5 text-[10px] font-medium text-muted">{{ t('roles.standard') }}</span>
                            <span v-else class="rounded-full bg-accent/15 px-1.5 py-0.5 text-[10px] font-medium text-primary">{{ t('roles.custom') }}</span>
                            <span v-if="role.modified" class="rounded-full bg-warning/15 px-1.5 py-0.5 text-[10px] font-medium text-warning">{{ t('roles.modified') }}</span>
                        </span>
                    </button>
                </li>
            </ul>

            <!-- Editor -->
            <section v-if="selected" class="min-w-0 rounded-lg border border-border bg-surface">
                <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-foreground">{{ selected.label }}</h2>
                        <p v-if="selected.description" class="mt-0.5 text-[13px] text-muted">{{ selected.description }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span v-if="dirty" class="text-xs font-medium text-warning">{{ t('roles.unsaved') }}</span>
                        <button v-if="selected.is_default && ! locked" type="button" class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-surface px-2.5 text-[13px] text-foreground hover:bg-background" @click="resetRole">
                            <AppIcon name="rotate" size="12" />
                            {{ t('roles.reset_role') }}
                        </button>
                        <button v-if="! selected.is_default" type="button" class="inline-flex h-7 items-center gap-1.5 rounded-md border border-danger/50 px-2.5 text-[13px] text-danger hover:bg-danger/10" @click="removeRole">
                            <AppIcon name="trash" size="12" />
                            {{ t('roles.delete_role') }}
                        </button>
                        <button v-if="! locked" type="button" :disabled="! dirty || saving" class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50" @click="save">
                            <AppIcon v-if="saving" name="spinner" spin />
                            {{ t('roles.save') }}
                        </button>
                    </div>
                </header>

                <p v-if="locked" class="mx-4 mt-3 rounded-md bg-primary/10 px-3 py-2 text-[13px] text-primary">{{ t('roles.locked_note') }}</p>

                <div class="space-y-2 border-b border-border px-4 py-3">
                    <SwitchField v-model="draftAllBranches" :label="t('roles.all_branches')" :disabled="locked" />
                    <p class="text-xs text-muted">{{ t('roles.all_branches_helper') }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2">
                    <input
                        v-model="query"
                        type="search"
                        :placeholder="t('roles.search')"
                        :aria-label="t('roles.search')"
                        class="h-7 w-56 rounded-md border border-border bg-background px-2 text-[13px] text-foreground outline-none focus:border-accent"
                    >
                    <span class="text-xs tabular-nums text-muted">{{ t('roles.selected_count', { selected: localDigits(locked ? allPermissions.length : draft.length), total: localDigits(allPermissions.length) }) }}</span>
                    <span v-if="! locked" class="ml-auto flex items-center gap-1">
                        <button type="button" class="h-7 rounded-md px-2 text-xs text-muted hover:bg-background hover:text-foreground" @click="selectEverything">{{ t('roles.select_all') }}</button>
                        <button type="button" class="h-7 rounded-md px-2 text-xs text-muted hover:bg-background hover:text-foreground" @click="clearEverything">{{ t('roles.clear_all') }}</button>
                    </span>
                </div>

                <div class="grid gap-3 p-4 md:grid-cols-2 2xl:grid-cols-3">
                    <fieldset v-for="entry in filteredGroups" :key="entry.group" class="rounded-lg border border-border">
                        <legend class="sr-only">{{ t('permissions.groups.' + entry.group) }}</legend>
                        <div class="flex items-center justify-between gap-2 border-b border-border bg-background/60 px-3 py-1.5">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted" :class="$page.props.locale === 'bn' ? 'normal-case tracking-normal' : ''">{{ t('permissions.groups.' + entry.group) }}</h3>
                            <button type="button" :disabled="locked" class="text-[11px] tabular-nums text-muted hover:text-foreground disabled:cursor-default disabled:hover:text-muted" :aria-label="t('roles.select_all')" @click="toggleGroup(entry.group)">
                                {{ localDigits(groupCount(entry.group)) }}/{{ localDigits(groups[entry.group].length) }}
                            </button>
                        </div>
                        <ul class="divide-y divide-border/60">
                            <li v-for="name in entry.names" :key="name">
                                <label class="flex cursor-pointer items-start gap-2 px-3 py-1.5 text-[13px] hover:bg-background/60" :class="locked || name === required ? 'cursor-default' : ''">
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 accent-[var(--color-primary)]"
                                        :checked="has(name) || name === required"
                                        :disabled="locked || name === required"
                                        @change="toggle(name)"
                                    >
                                    <span class="min-w-0">
                                        <span class="block text-foreground">{{ t('permissions.items.' + name) }}</span>
                                        <span v-if="name === required" class="block text-[11px] text-muted">{{ t('roles.required_note') }}</span>
                                        <code v-else class="block truncate text-[10px] text-muted">{{ name }}</code>
                                    </span>
                                </label>
                            </li>
                        </ul>
                    </fieldset>
                </div>
            </section>
        </div>

        <!-- New role -->
        <SlideOver :open="createOpen" :title="t('roles.create_title')" @close="createOpen = false">
            <form class="space-y-3" @submit.prevent="create">
                <FormField :label="t('roles.role_name')" required :error="createForm.errors.label">
                    <template #default="{ id }">
                        <input :id="id" v-model="createForm.label" type="text" maxlength="80" class="h-7 w-full rounded-md border bg-surface px-2 text-[13px] text-foreground outline-none focus:ring-2 focus:ring-accent/40" :class="createForm.errors.label ? 'border-danger' : 'border-border focus:border-accent'">
                    </template>
                </FormField>
                <FormField :label="t('roles.role_name_bn')" :error="createForm.errors.label_bn">
                    <template #default="{ id }">
                        <input :id="id" v-model="createForm.label_bn" type="text" maxlength="80" class="h-7 w-full rounded-md border border-border bg-surface px-2 text-[13px] text-foreground outline-none focus:border-accent focus:ring-2 focus:ring-accent/40">
                    </template>
                </FormField>
                <FormField :label="t('roles.description')" :error="createForm.errors.description">
                    <template #default="{ id }">
                        <input :id="id" v-model="createForm.description" type="text" maxlength="255" class="h-7 w-full rounded-md border border-border bg-surface px-2 text-[13px] text-foreground outline-none focus:border-accent focus:ring-2 focus:ring-accent/40">
                    </template>
                </FormField>
                <FormField :label="t('roles.copy_from')" :error="createForm.errors.copy_from">
                    <template #default="{ id }">
                        <select :id="id" v-model="createForm.copy_from" class="h-7 w-full rounded-md border border-border bg-surface px-2 text-[13px] text-foreground outline-none focus:border-accent">
                            <option value="">{{ t('roles.copy_from_none') }}</option>
                            <option v-for="role in roles.filter((entry) => ! entry.is_locked)" :key="role.name" :value="role.name">{{ role.label }}</option>
                        </select>
                    </template>
                </FormField>
                <SwitchField v-model="createForm.all_branches" :label="t('roles.all_branches')" />
            </form>

            <template #footer>
                <button type="button" class="h-7 rounded-md border border-border bg-surface px-2.5 text-[13px] text-foreground hover:bg-background" @click="createOpen = false">{{ t('common.cancel') }}</button>
                <button type="button" :disabled="createForm.processing" class="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2.5 text-[13px] font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60" @click="create">
                    <AppIcon v-if="createForm.processing" name="spinner" spin />
                    {{ t('roles.create') }}
                </button>
            </template>
        </SlideOver>
    </div>
</template>
