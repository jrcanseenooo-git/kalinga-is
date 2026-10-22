<script setup lang="ts">
import { formatPh } from '@/utils/philippineTime';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import SectionNav from '@/Components/SectionNav.vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { Check, ChevronLeft, ChevronRight, Clock, MapPin, Monitor, Search, Shield, ShieldAlert, ShieldCheck, ShieldX, Smartphone, UserCheck, UserPlus, UserX, Users, X } from '@lucide/vue'
import { computed, onMounted, ref, watch, type Component } from 'vue'
import { locationDatasetUrl, type LocationDataset } from '@/data/locations'

const props = defineProps<{
  users: any[]
  roles: any[]
  references: any[]
  audits: any[]
  lguUnits: any[]
  lguAssignments: any[]
  pendingRegistrations: any[]
  registrableRoles: Record<string, string>
  cpuDisciplines: Record<string, string>
}>()

const flash = computed(() => (usePage().props.flash as any)?.success || '')

const activeTab = ref<'users' | 'pending' | 'audit'>('users')
const tabs = computed<Array<{ key: 'users' | 'pending' | 'audit'; label: string; icon: Component; badge?: number }>>(() => [
  { key: 'users', label: 'All Users', icon: Users },
  { key: 'pending', label: 'Pending', icon: UserPlus, badge: props.pendingRegistrations.length },
  { key: 'audit', label: 'Activity Log', icon: Shield },
])
const searchQuery = ref('')
const roleFilter = ref('')
const statusFilter = ref<'' | 'active' | 'inactive'>('')

const filteredUsers = computed(() => {
  return props.users.filter(u => {
    if (searchQuery.value) {
      const q = searchQuery.value.toLowerCase()
      if (!u.name.toLowerCase().includes(q) && !u.email.toLowerCase().includes(q)) return false
    }
    if (roleFilter.value && u.role !== roleFilter.value) return false
    if (statusFilter.value === 'active' && !u.active) return false
    if (statusFilter.value === 'inactive' && u.active) return false
    return true
  })
})

const currentPage = ref(1)
const perPage = ref(10)
const totalPages = computed(() => Math.max(1, Math.ceil(filteredUsers.value.length / perPage.value)))
const paginatedUsers = computed(() => {
  const start = (currentPage.value - 1) * perPage.value
  return filteredUsers.value.slice(start, start + perPage.value)
})

watch([searchQuery, roleFilter, statusFilter, perPage], () => { currentPage.value = 1 })

const activeCount = computed(() => props.users.filter(u => u.active).length)
const inactiveCount = computed(() => props.users.filter(u => !u.active).length)

const selectedUser = ref<any>(null)
const deletingUser = ref<any>(null)
const confirmingFinalDelete = ref(false)
const confirmingDeactivation = ref(false)
const deactivationConfirmed = ref(false)
const deleteConfirmation = ref('')
const deleteError = ref('')
const deleteBusy = ref(false)
const editForm = useForm({ role: '', cpu_discipline: '', active: true, region: '', province: '', municipality: '' })
const editLgu = useForm({ lgu_id: '', effective_from: new Date().toISOString().slice(0, 10) })
const detailTab = ref<'settings' | 'devices'>('settings')
const locations = ref<LocationDataset | null>(null)
const selectedRegion = computed(() => locations.value?.regions.find(region => region.name === editForm.region) ?? null)
const provinceOptions = computed(() => selectedRegion.value?.provinces ?? [])
const municipalityOptions = computed(() => {
  if (!selectedRegion.value) return []
  if (editForm.province) return selectedRegion.value.provinces.find(province => province.name === editForm.province)?.municipalities ?? []
  return selectedRegion.value.municipalities ?? []
})
const setRegion = (value: string) => { editForm.region = value; editForm.province = ''; editForm.municipality = '' }
const setProvince = (value: string) => { editForm.province = value; editForm.municipality = '' }

const roleDescriptions: Record<string, { scope: string; hint: string }> = {
  system_admin: { scope: 'National', hint: 'Full system access. No LGU or geographic restriction.' },
  social_worker: { scope: 'LGU', hint: 'Case management within assigned LGU. Requires LGU assignment.' },
  cpu_member: { scope: 'LGU', hint: 'CPU coordination within assigned LGU. Requires LGU assignment and discipline.' },
  fo_user: { scope: 'Regional', hint: 'Aggregate oversight across the assigned region. No LGU assignment needed.' },
}
const needsLgu = computed(() => editForm.role === 'social_worker' || editForm.role === 'cpu_member')

watch(() => editForm.role, (newRole, oldRole) => {
  if (newRole === oldRole) return
  if (newRole !== 'cpu_member') editForm.cpu_discipline = ''
  if (newRole !== 'fo_user') { editForm.region = ''; editForm.province = ''; editForm.municipality = '' }
  if (newRole === 'fo_user') { editForm.province = ''; editForm.municipality = '' }
})
const officialLguName = (lgu: any) => {
  const municipalities = locations.value?.regions.flatMap(region => [...region.municipalities, ...region.provinces.flatMap(province => province.municipalities)]) ?? []
  const match = municipalities.find(municipality => municipality.code === lgu?.psgc_code)
  const normalizedName = String(lgu?.name ?? '').replace(/^(city|municipality) of /i, '').trim().toLowerCase()
  const nameMatches = normalizedName ? municipalities.filter(municipality => municipality.name.replace(/^(city|municipality) of /i, '').trim().toLowerCase() === normalizedName) : []
  const legacyMatch = nameMatches.length === 1 ? nameMatches[0] : null
  return match?.name || legacyMatch?.name || lgu?.name || 'Unassigned LGU'
}
onMounted(async () => {
  try {
    const response = await fetch(locationDatasetUrl, { cache: 'force-cache' })
    if (response.ok) locations.value = await response.json()
  } catch { locations.value = null }
})

const selectUser = (user: any) => {
  selectedUser.value = user
  editForm.role = user.role
  editForm.cpu_discipline = user.cpu_discipline || ''
  editForm.active = Boolean(user.active)
  editForm.region = user.region || ''
  editForm.province = user.province || ''
  editForm.municipality = user.municipality || ''
  editLgu.lgu_id = currentLgu(user.id)?.toString() || ''
  detailTab.value = 'settings'
  loadUserDevices(user.id)
}

const closeDetail = () => { selectedUser.value = null }
const openDeleteUser = () => { deletingUser.value = selectedUser.value; deleteConfirmation.value = ''; deleteError.value = ''; confirmingFinalDelete.value = false; selectedUser.value = null }
const closeDeleteUser = () => { if (!deleteBusy.value) { deletingUser.value = null; confirmingFinalDelete.value = false } }
const deleteUser = () => { if (!deletingUser.value || deleteConfirmation.value !== deletingUser.value.email) { deleteError.value = 'Type the exact account email to confirm.'; return }; confirmingFinalDelete.value = true }
const cancelFinalDelete = () => { if (!deleteBusy.value) confirmingFinalDelete.value = false }
const confirmDeleteUser = () => { if (!deletingUser.value || deleteConfirmation.value !== deletingUser.value.email) { confirmingFinalDelete.value = false; deleteError.value = 'Type the exact account email to confirm.'; return }; deleteBusy.value = true; router.delete(route('admin.users.destroy', deletingUser.value.id), { data: { confirmation: deleteConfirmation.value }, preserveScroll: true, onError: errors => { confirmingFinalDelete.value = false; deleteError.value = String(errors.confirmation || errors.delete || 'This account could not be deleted.') }, onSuccess: () => { confirmingFinalDelete.value = false; deletingUser.value = null; selectedUser.value = null }, onFinish: () => { deleteBusy.value = false } }) }

const saveUser = () => {
  if (!selectedUser.value) return
  if (!editForm.active && Boolean(selectedUser.value.active) && !deactivationConfirmed.value) {
    confirmingDeactivation.value = true
    return
  }
  editForm.patch(route('admin.users.update', selectedUser.value.id), {
    preserveScroll: true,
    onSuccess: () => closeDetail(),
  })
}
const cancelDeactivation = () => { confirmingDeactivation.value = false; editForm.active = true }
const confirmDeactivation = () => { confirmingDeactivation.value = false; deactivationConfirmed.value = true; saveUser(); deactivationConfirmed.value = false }

const currentLgu = (userId: number) => props.lguAssignments.find((a: any) => a.user_id === userId)?.lgu_id || ''
const currentLguName = (userId: number) => {
  const lguId = currentLgu(userId)
  if (!lguId) return null
  return props.lguUnits.find((l: any) => l.id === lguId)
}

const assignLgu = () => {
  if (!selectedUser.value || !editLgu.lgu_id) return
  editLgu.post(route('admin.users.lgu-assignment.store', selectedUser.value.id), { preserveScroll: true })
}

type AdminDevice = { id:number; device_id:string; device_name:string|null; last_seen_at:string; pack_expires_at:string|null; revoked_at:string|null; created_at:string };
const userDevices = ref<AdminDevice[]>([]);
const devicesLoading = ref(false);
async function loadUserDevices(userId: number) {
  devicesLoading.value = true;
  try { const { data } = await axios.get(route('admin.users.devices.index', userId)); userDevices.value = data.devices; }
  catch { userDevices.value = []; }
  finally { devicesLoading.value = false; }
}
async function adminRevokeDevice(deviceId: number) {
  try { await axios.post(route('admin.devices.revoke', deviceId)); if (selectedUser.value) loadUserDevices(selectedUser.value.id); }
  catch { /* silently fail */ }
}

const roleName = (code: string) => props.roles.find((r: any) => r.code === code)?.name || code
const roleShort = (code: string) => roleName(code).split('/')[0].replace(/\s*\(.*\)/, '').trim()
const disciplineName = (code: string) => props.cpuDisciplines?.[code] || code

const roleColor = (role: string) => {
  const map: Record<string, { bg: string; text: string; ring: string; dot: string; avatar: string; light: string }> = {
    system_admin: { bg: 'bg-violet-50', text: 'text-violet-700', ring: 'ring-violet-200/60', dot: 'bg-violet-500', avatar: 'from-violet-500 to-purple-700', light: 'bg-violet-500/10' },
    social_worker: { bg: 'bg-sky-50', text: 'text-sky-700', ring: 'ring-sky-200/60', dot: 'bg-sky-500', avatar: 'from-sky-500 to-blue-700', light: 'bg-sky-500/10' },
    cpu_member: { bg: 'bg-amber-50', text: 'text-amber-700', ring: 'ring-amber-200/60', dot: 'bg-amber-500', avatar: 'from-amber-500 to-orange-700', light: 'bg-amber-500/10' },
    fo_user: { bg: 'bg-teal-50', text: 'text-teal-700', ring: 'ring-teal-200/60', dot: 'bg-teal-500', avatar: 'from-teal-500 to-emerald-700', light: 'bg-teal-500/10' },
  }
  return map[role] || { bg: 'bg-slate-50', text: 'text-slate-600', ring: 'ring-slate-200', dot: 'bg-slate-400', avatar: 'from-slate-400 to-slate-600', light: 'bg-slate-500/10' }
}

const approvalRole = ref<Record<number, string>>({})

const approve = (reg: any) => {
  const role = approvalRole.value[reg.id] || reg.requested_role
  if (!confirm(`Approve ${reg.name} as ${roleName(role)}?`)) return
  const form = useForm({ role })
  form.post(route('admin.registrations.approve', reg.id), { preserveScroll: true })
}

const decline = (reg: any) => {
  if (!confirm(`Decline registration for ${reg.name}? This action cannot be undone.`)) return
  useForm({}).post(route('admin.registrations.decline', reg.id), { preserveScroll: true })
}

const auditFilter = ref('')
const auditLabels: Record<string, string> = {
  'user.access_updated': 'Access updated',
  'user.self_registered': 'Self-registration',
  'user.registration_approved': 'Registration approved',
  'user.registration_declined': 'Registration declined',
  'user.lgu_assigned': 'LGU assignment changed',
  'lgu.created': 'LGU unit added',
  'case_plan.created': 'Case plan created',
  'case_plan.updated': 'Case plan updated',
  'assessment.saved': 'Assessment saved',
  'case_note.created': 'Case note added',
  'referral.created': 'Referral created',
  'referral.responded': 'Referral responded',
  'document.uploaded': 'Document uploaded',
  'report.created': 'Report submitted',
  'report.transferred': 'Report transferred',
  'case.created': 'Case opened',
  'case.closed': 'Case closed',
  'case.reopened': 'Case reopened',
  'conference.created': 'Case conference created',
  'task.created': 'Task created',
  'task.transitioned': 'Task updated',
  'task.marked_stuck': 'Task marked stuck',
  'test_field.confirmed': 'Field test confirmed',
  'record.retention.reviewed': 'Retention reviewed',
  'record.disposition.proposed': 'Disposition proposed',
  'identity.candidates.resolved': 'Identity resolved',
  'identity_merge.proposed': 'Identity merge proposed',
  'management_report.exported': 'Report exported',
  'management_report.generated': 'Report generated',
  'public_report.submitted': 'Public report submitted',
  'export.transferred': 'Export transferred',
  'analytics.exported': 'Analytics exported',
  'child_participation.centers_saved': 'Participation saved',
  'offline.device_revoked': 'Device revoked',
  'offline.device_revoked_by_admin': 'Device revoked (admin)',
  'offline.sync': 'Offline sync',
  'offline.case_intake_synced': 'Offline intake synced',
  'offline.intake_synced': 'Offline report synced',
}
const auditLabel = (event: string) => auditLabels[event] || event.replace(/[._]/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

const filteredAudits = computed(() => {
  if (!auditFilter.value) return props.audits.slice(0, 80)
  const q = auditFilter.value.toLowerCase()
  return props.audits.filter(a => auditLabel(a.event).toLowerCase().includes(q) || (a.user_name || '').toLowerCase().includes(q)).slice(0, 80)
})

const auditIcon = (event: string) => {
  if (event?.includes('approved') || event?.includes('synced')) return 'approved'
  if (event?.includes('declined') || event?.includes('revoked') || event?.includes('closed')) return 'declined'
  if (event?.includes('updated') || event?.includes('assigned') || event?.includes('created')) return 'changed'
  return 'default'
}

const roleDistribution = computed(() => {
  const counts: Record<string, number> = {}
  for (const u of props.users) {
    if (u.active) counts[u.role] = (counts[u.role] || 0) + 1
  }
  return counts
})

const fmtDate = (d: string) => formatPh(d, 'date')

const fmtDateTime = (d: string) => formatPh(d, 'dateTimeShort')
</script>

<template>
  <Head title="User management" />
  <AuthenticatedLayout>
    <div class="w-full max-w-none space-y-4">
      <SectionNav section="admin" />
      <div v-if="flash" class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-sm font-medium text-emerald-700">{{ flash }}</div>

      <!-- ── TABS ── -->
      <nav class="flex" role="tablist">
        <div class="flex w-full gap-1 rounded-2xl bg-slate-100 p-1">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            role="tab"
            :aria-selected="activeTab === tab.key"
            class="relative flex flex-1 items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-sm font-bold transition-all"
            :class="activeTab === tab.key
              ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200/60'
              : 'text-slate-600 hover:text-slate-800'"
            @click="activeTab = tab.key"
          >
            <component :is="tab.icon" class="h-4 w-4" />
            {{ tab.label }}
            <span v-if="tab.badge" class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-amber-500 px-1.5 text-[10px] font-black text-white shadow-sm">{{ tab.badge }}</span>
          </button>
        </div>
      </nav>

      <!-- TAB: ALL USERS -->
      <div v-if="activeTab === 'users'" class="mt-4">
        <!-- Search & filter -->
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <div class="relative flex-1">
            <Search class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
            <input
              v-model="searchQuery" type="search" placeholder="Search by name or email"
              class="w-full rounded-xl border-0 bg-white py-2.5 pl-10 pr-4 text-sm shadow-sm ring-1 ring-slate-200/60 transition placeholder:text-slate-400 focus:ring-2 focus:ring-brand-500"
            />
          </div>
          <div class="grid grid-cols-2 gap-2 sm:flex">
            <select v-model="roleFilter" aria-label="Filter users by role" class="min-w-0 rounded-xl border-0 bg-white py-2.5 pr-8 text-sm shadow-sm ring-1 ring-slate-200/60 focus:ring-2 focus:ring-brand-500">
              <option value="">All roles</option>
              <option v-for="role in props.roles" :key="role.code" :value="role.code">{{ role.name }}</option>
            </select>
            <select v-model="statusFilter" aria-label="Filter users by status" class="min-w-0 rounded-xl border-0 bg-white py-2.5 pr-8 text-sm shadow-sm ring-1 ring-slate-200/60 focus:ring-2 focus:ring-brand-500">
              <option value="">All status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>

        <!-- Desktop: Table view -->
        <div class="mt-3 hidden overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200/60 md:block">
          <table class="w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-600">
                <th class="py-3 pl-5 pr-2 font-bold">User</th>
                <th class="px-3 py-3 font-bold">Role</th>
                <th class="px-3 py-3 font-bold">Location</th>
                <th class="px-3 py-3 font-bold">Status</th>
                <th class="w-10 py-3 pr-4"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
              <tr
                v-for="user in paginatedUsers" :key="user.id"
                class="group cursor-pointer transition hover:bg-brand-50/40"
                :class="selectedUser?.id === user.id ? 'bg-brand-50/60' : ''"
                @click="selectUser(user)"
              >
                <td class="py-3 pl-5 pr-2">
                  <div class="flex items-center gap-3">
                    <div class="relative shrink-0">
                      <div
                        class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br text-xs font-black text-white"
                        :class="user.active ? roleColor(user.role).avatar : 'from-slate-300 to-slate-400'"
                      >{{ user.name.charAt(0).toUpperCase() }}</div>
                      <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white" :class="user.active ? 'bg-emerald-400' : 'bg-slate-300'" />
                    </div>
                    <div class="min-w-0">
                      <p class="truncate text-sm font-semibold text-slate-900">{{ user.name }}</p>
                      <p class="truncate text-xs text-slate-600">{{ user.email }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-3 py-3">
                  <span class="inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-[11px] font-bold ring-1" :class="[roleColor(user.role).bg, roleColor(user.role).text, roleColor(user.role).ring]">
                    <span class="h-1.5 w-1.5 rounded-full" :class="roleColor(user.role).dot" />
                    {{ roleShort(user.role) }}
                  </span>
                </td>
                <td class="px-3 py-3">
                  <span v-if="user.municipality || user.province" class="inline-flex items-center gap-1 text-xs text-slate-500">
                    <MapPin class="h-3 w-3 text-slate-500" />{{ [user.municipality, user.province].filter(Boolean).join(', ') }}
                  </span>
                  <span v-else class="text-xs text-slate-500">-</span>
                </td>
                <td class="px-3 py-3">
                  <span class="inline-flex items-center gap-1.5 text-xs font-semibold" :class="user.active ? 'text-emerald-700' : 'text-slate-600'">
                    <span class="h-2 w-2 rounded-full" :class="user.active ? 'bg-emerald-400' : 'bg-slate-300'" />
                    {{ user.active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td class="py-3 pr-4 text-right">
                  <ChevronRight class="inline h-4 w-4 text-slate-500 transition group-hover:text-brand-500" />
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="!filteredUsers.length" class="flex flex-col items-center py-16 text-center">
            <Search class="h-8 w-8 text-slate-200" />
            <p class="mt-2 text-sm font-semibold text-slate-500">No users found</p>
            <p class="mt-1 text-xs text-slate-500">Try adjusting your search or filters.</p>
          </div>
          <div v-else class="border-t border-slate-100 px-5 py-3">
            <div v-if="totalPages > 1" class="flex w-full items-center gap-1.5">
              <button :disabled="currentPage <= 1" class="grid h-9 w-9 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition-all hover:border-brand-300 hover:text-brand-600 hover:shadow-md disabled:opacity-30 disabled:shadow-none" @click="currentPage--">
                <ChevronLeft class="h-4 w-4" />
              </button>
              <div class="flex flex-1 items-center gap-0.5 rounded-xl bg-slate-50/80 p-1 ring-1 ring-slate-200/50">
                <template v-for="p in totalPages" :key="p">
                  <button
                    v-if="p === 1 || p === totalPages || (p >= currentPage - 1 && p <= currentPage + 1)"
                    class="relative grid h-8 flex-1 place-items-center rounded-lg text-sm font-bold transition-all duration-200"
                    :class="p === currentPage ? 'bg-brand-700 text-white' : 'text-slate-500 hover:bg-white hover:text-slate-700 hover:shadow-sm'"
                    @click="currentPage = p"
                  >{{ p }}</button>
                  <span v-else-if="p === currentPage - 2 || p === currentPage + 2" class="grid h-8 w-6 shrink-0 place-items-center text-xs text-slate-500 select-none">•••</span>
                </template>
              </div>
              <button :disabled="currentPage >= totalPages" class="grid h-9 w-9 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition-all hover:border-brand-300 hover:text-brand-600 hover:shadow-md disabled:opacity-30 disabled:shadow-none" @click="currentPage++">
                <ChevronRight class="h-4 w-4" />
              </button>
            </div>
            <div class="flex items-center justify-between" :class="totalPages > 1 ? 'mt-2.5' : ''">
              <span class="text-xs text-slate-600">Showing <span class="font-bold text-slate-700">{{ (currentPage - 1) * perPage + 1 }}-{{ Math.min(currentPage * perPage, filteredUsers.length) }}</span> of <span class="font-bold text-slate-700">{{ filteredUsers.length }}</span> users</span>
              <div class="flex items-center gap-1.5 text-[11px] text-slate-600">
                <span class="font-medium">Rows</span>
                <div class="flex items-center rounded-lg bg-slate-50 p-0.5 ring-1 ring-slate-200/60">
                  <button v-for="opt in [10, 25, 50]" :key="opt" type="button" :aria-pressed="perPage === opt" :aria-label="`${opt} per page`" class="min-h-9 min-w-9 rounded-md px-2.5 text-xs font-bold transition-all" :class="perPage === opt ? 'bg-white text-slate-800 shadow-sm ring-1 ring-slate-200/60' : 'text-slate-600 hover:text-slate-800'" @click="perPage = opt">{{ opt }}</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Mobile: Card view -->
        <div class="mt-3 space-y-1.5 md:hidden">
          <button
            v-for="user in paginatedUsers" :key="user.id"
            class="group flex w-full items-center gap-3 rounded-xl bg-white px-3.5 py-3 text-left shadow-sm ring-1 transition-all hover:shadow-md"
            :class="selectedUser?.id === user.id ? 'ring-brand-400 shadow-brand-500/10' : 'ring-slate-200/60 hover:ring-brand-300'"
            @click="selectUser(user)"
          >
            <div class="relative shrink-0">
              <div
                class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br text-sm font-black text-white"
                :class="user.active ? roleColor(user.role).avatar : 'from-slate-300 to-slate-400'"
              >{{ user.name.charAt(0).toUpperCase() }}</div>
              <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white" :class="user.active ? 'bg-emerald-400' : 'bg-slate-300'" />
            </div>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-bold text-slate-900">{{ user.name }}</p>
              <p class="mt-0.5 truncate text-xs text-slate-600">{{ user.email }}</p>
              <div class="mt-1 flex flex-wrap items-center gap-1.5">
                <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-bold ring-1" :class="[roleColor(user.role).bg, roleColor(user.role).text, roleColor(user.role).ring]">
                  <span class="h-1.5 w-1.5 rounded-full" :class="roleColor(user.role).dot" />{{ roleShort(user.role) }}
                </span>
                <span v-if="user.municipality" class="text-[10px] text-slate-600">{{ user.municipality }}</span>
              </div>
            </div>
            <ChevronRight class="h-4 w-4 shrink-0 text-slate-500 group-hover:text-brand-500" />
          </button>
          <p v-if="!filteredUsers.length" class="py-12 text-center text-sm text-slate-500">No users found.</p>
          <div v-else class="mt-2 rounded-2xl bg-white px-3.5 py-3 shadow-sm ring-1 ring-slate-200/60">
            <div v-if="totalPages > 1" class="flex w-full items-center gap-1.5">
              <button :disabled="currentPage <= 1" class="grid h-9 w-9 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition-all active:scale-95 disabled:opacity-30 disabled:shadow-none" @click="currentPage--">
                <ChevronLeft class="h-4 w-4" />
              </button>
              <div class="flex flex-1 items-center gap-0.5 rounded-xl bg-slate-50/80 p-0.5 ring-1 ring-slate-200/50">
                <template v-for="p in totalPages" :key="p">
                  <button
                    v-if="p === 1 || p === totalPages || (p >= currentPage - 1 && p <= currentPage + 1)"
                    class="grid h-8 flex-1 place-items-center rounded-lg text-sm font-bold transition-all duration-200 active:scale-95"
                    :class="p === currentPage ? 'bg-brand-700 text-white' : 'text-slate-500 hover:bg-white hover:shadow-sm'"
                    @click="currentPage = p"
                  >{{ p }}</button>
                  <span v-else-if="p === currentPage - 2 || p === currentPage + 2" class="grid h-8 w-5 shrink-0 place-items-center text-xs text-slate-500 select-none">•••</span>
                </template>
              </div>
              <button :disabled="currentPage >= totalPages" class="grid h-9 w-9 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition-all active:scale-95 disabled:opacity-30 disabled:shadow-none" @click="currentPage++">
                <ChevronRight class="h-4 w-4" />
              </button>
            </div>
            <div class="flex items-center justify-between" :class="totalPages > 1 ? 'mt-2.5' : ''">
              <span class="text-[11px] text-slate-600">Showing <span class="font-bold text-slate-700">{{ (currentPage - 1) * perPage + 1 }}-{{ Math.min(currentPage * perPage, filteredUsers.length) }}</span> of <span class="font-bold text-slate-700">{{ filteredUsers.length }}</span></span>
              <div class="flex items-center gap-1 rounded-lg bg-slate-50 p-0.5 ring-1 ring-slate-200/60">
                <button v-for="opt in [10, 25, 50]" :key="opt" type="button" :aria-pressed="perPage === opt" :aria-label="`${opt} per page`" class="min-h-9 min-w-9 rounded-md px-2 text-xs font-bold transition-all" :class="perPage === opt ? 'bg-white text-slate-800 shadow-sm ring-1 ring-slate-200/60' : 'text-slate-600'" @click="perPage = opt">{{ opt }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB: PENDING -->
      <div v-if="activeTab === 'pending'" class="mt-4">
        <div v-if="!props.pendingRegistrations.length" class="flex flex-col items-center justify-center rounded-2xl bg-white py-16 shadow-sm ring-1 ring-slate-200/60">
          <div class="grid h-16 w-16 place-items-center rounded-2xl bg-emerald-50">
            <UserCheck class="h-7 w-7 text-emerald-400" />
          </div>
          <p class="mt-4 text-sm font-bold text-slate-600">All clear</p>
          <p class="mt-1 text-sm text-slate-500">No pending registrations to review.</p>
        </div>

        <div v-else class="space-y-3">
          <div v-for="reg in props.pendingRegistrations" :key="reg.id" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-amber-200/50">
            <div class="flex items-start gap-4">
              <div class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-amber-400 to-orange-500 text-sm font-black text-white shadow-md">
                {{ reg.name.charAt(0).toUpperCase() }}
              </div>
              <div class="min-w-0 flex-1">
                <p class="text-[15px] font-bold text-slate-900">{{ reg.name }}</p>
                <p class="text-xs text-slate-500">{{ reg.email }}</p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                  <span class="inline-flex items-center gap-1 rounded-lg bg-slate-50 px-2 py-0.5 text-[11px] font-bold text-slate-600 ring-1 ring-slate-200/60">
                    <Shield class="h-3 w-3" />{{ props.registrableRoles[reg.requested_role] || reg.requested_role }}
                  </span>
                  <span v-if="reg.cpu_discipline" class="inline-flex items-center gap-1 rounded-lg bg-slate-50 px-2 py-0.5 text-[11px] font-bold text-slate-600 ring-1 ring-slate-200/60">
                    {{ disciplineName(reg.cpu_discipline) }}
                  </span>
                  <span v-if="reg.municipality || reg.region" class="inline-flex items-center gap-1 rounded-lg bg-slate-50 px-2 py-0.5 text-[11px] font-bold text-slate-600 ring-1 ring-slate-200/60">
                    <MapPin class="h-3 w-3" />{{ [reg.municipality, reg.province, reg.region].filter(Boolean).join(', ') }}
                  </span>
                </div>
                <p class="mt-2 text-[11px] text-slate-500">Registered {{ fmtDate(reg.created_at) }}</p>
              </div>
            </div>

            <div class="mt-4 flex flex-col gap-2.5 border-t border-slate-100 pt-4 sm:flex-row sm:items-center">
              <label class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                Assign as:
                <select v-model="approvalRole[reg.id]" class="rounded-xl border-0 bg-slate-50 py-1.5 text-sm ring-1 ring-slate-200/60 focus:ring-2 focus:ring-brand-500">
                  <option v-for="role in props.roles" :key="role.code" :value="role.code" :selected="role.code === reg.requested_role">{{ role.name }}</option>
                </select>
              </label>
              <div class="flex gap-2 sm:ml-auto">
                <button class="inline-flex min-h-[2.75rem] items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-emerald-600/20 transition hover:bg-emerald-700 hover:-translate-y-px active:translate-y-0" @click="approve(reg)">
                  <UserCheck class="h-4 w-4" />Approve
                </button>
                <button class="inline-flex min-h-[2.75rem] items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-bold text-rose-600 shadow-sm ring-1 ring-rose-200 transition hover:bg-rose-50" @click="decline(reg)">
                  <UserX class="h-4 w-4" />Decline
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB: ACTIVITY -->
      <div v-if="activeTab === 'audit'" class="mt-4">
        <div class="relative mb-3">
          <Search class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
          <input v-model="auditFilter" type="search" placeholder="Filter activity…" class="w-full rounded-xl border-0 bg-white py-2.5 pl-10 pr-4 text-sm shadow-sm ring-1 ring-slate-200/60 transition placeholder:text-slate-400 focus:ring-2 focus:ring-brand-500" />
        </div>

        <!-- Desktop: Table layout -->
        <div class="hidden overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200/60 md:block">
          <table class="w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                <th class="py-3 pl-5 pr-2 font-bold">Event</th>
                <th class="px-3 py-3 font-bold">User</th>
                <th class="px-3 py-3 text-right font-bold">Date</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
              <tr v-for="audit in filteredAudits" :key="audit.id" class="transition hover:bg-brand-50/40">
                <td class="py-3 pl-5 pr-2">
                  <div class="flex items-center gap-3">
                    <div class="grid h-8 w-8 shrink-0 place-items-center rounded-lg" :class="{
                      'bg-emerald-50': auditIcon(audit.event) === 'approved',
                      'bg-rose-50': auditIcon(audit.event) === 'declined',
                      'bg-amber-50': auditIcon(audit.event) === 'changed',
                      'bg-slate-50': auditIcon(audit.event) === 'default',
                    }">
                      <ShieldCheck v-if="auditIcon(audit.event) === 'approved'" class="h-4 w-4 text-emerald-600" />
                      <ShieldX v-else-if="auditIcon(audit.event) === 'declined'" class="h-4 w-4 text-rose-500" />
                      <ShieldAlert v-else-if="auditIcon(audit.event) === 'changed'" class="h-4 w-4 text-amber-500" />
                      <Shield v-else class="h-4 w-4 text-slate-500" />
                    </div>
                    <span class="text-sm font-semibold text-slate-800">{{ auditLabel(audit.event) }}</span>
                  </div>
                </td>
                <td class="px-3 py-3 text-sm text-slate-500">{{ audit.user_name || 'System' }}</td>
                <td class="px-3 py-3 text-right text-xs text-slate-500">{{ fmtDateTime(audit.created_at) }}</td>
              </tr>
            </tbody>
          </table>
          <div v-if="!filteredAudits.length" class="flex flex-col items-center py-16 text-center">
            <Search class="h-8 w-8 text-slate-200" />
            <p class="mt-2 text-sm font-semibold text-slate-500">{{ auditFilter ? 'No matching activity' : 'No audit activity yet' }}</p>
            <p v-if="auditFilter" class="mt-1 text-xs text-slate-500">Try adjusting your filter.</p>
          </div>
        </div>

        <!-- Mobile: Card layout -->
        <div class="space-y-1.5 md:hidden">
          <div v-for="audit in filteredAudits" :key="audit.id" class="flex items-center gap-3 rounded-xl bg-white px-3.5 py-3 shadow-sm ring-1 ring-slate-200/60">
            <div class="grid h-9 w-9 shrink-0 place-items-center rounded-xl" :class="{
              'bg-emerald-50': auditIcon(audit.event) === 'approved',
              'bg-rose-50': auditIcon(audit.event) === 'declined',
              'bg-amber-50': auditIcon(audit.event) === 'changed',
              'bg-slate-50': auditIcon(audit.event) === 'default',
            }">
              <ShieldCheck v-if="auditIcon(audit.event) === 'approved'" class="h-4 w-4 text-emerald-600" />
              <ShieldX v-else-if="auditIcon(audit.event) === 'declined'" class="h-4 w-4 text-rose-500" />
              <ShieldAlert v-else-if="auditIcon(audit.event) === 'changed'" class="h-4 w-4 text-amber-500" />
              <Shield v-else class="h-4 w-4 text-slate-500" />
            </div>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-bold text-slate-800">{{ auditLabel(audit.event) }}</p>
              <div class="mt-0.5 flex items-center gap-2">
                <span class="text-[11px] text-slate-500">{{ audit.user_name || 'System' }}</span>
                <span class="text-slate-200">·</span>
                <time class="text-[11px] text-slate-500">{{ fmtDateTime(audit.created_at) }}</time>
              </div>
            </div>
          </div>
          <p v-if="!filteredAudits.length" class="py-12 text-center text-sm text-slate-500">{{ auditFilter ? 'No matching activity.' : 'No audit activity yet.' }}</p>
        </div>
      </div>
    </div>

    <!-- MOBILE SLIDE-OVER (< lg) -->
    <Teleport to="body">
      <Transition name="panel">
        <div v-if="selectedUser" class="fixed inset-0 z-50 flex justify-end" @click.self="closeDetail">
          <div class="absolute inset-0 bg-slate-950/40 backdrop-blur-sm" @click="closeDetail" />
          <aside class="relative z-10 flex w-full max-w-[26rem] flex-col overflow-hidden bg-white shadow-2xl">
            <!-- Mobile header -->
            <div class="relative shrink-0 overflow-hidden border-b border-slate-100">
              <div class="absolute inset-0 bg-gradient-to-br opacity-[.04]" :class="[roleColor(selectedUser.role).avatar]" />
              <div class="relative flex items-center gap-3.5 px-5 py-4">
                <div class="relative">
                  <div
                    class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br text-lg font-black text-white shadow-lg"
                    :class="selectedUser.active ? roleColor(selectedUser.role).avatar : 'from-slate-300 to-slate-400'"
                  >{{ selectedUser.name.charAt(0).toUpperCase() }}</div>
                  <span class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-2 border-white" :class="selectedUser.active ? 'bg-emerald-400' : 'bg-slate-300'" />
                </div>
                <div class="min-w-0 flex-1">
                  <h2 class="truncate text-base font-extrabold text-slate-900">{{ selectedUser.name }}</h2>
                  <p class="truncate text-xs text-slate-500">{{ selectedUser.email }}</p>
                  <span class="mt-1 inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-[11px] font-bold ring-1" :class="[roleColor(selectedUser.role).bg, roleColor(selectedUser.role).text, roleColor(selectedUser.role).ring]">
                    <span class="h-1.5 w-1.5 rounded-full" :class="roleColor(selectedUser.role).dot" />{{ roleShort(selectedUser.role) }}
                  </span>
                </div>
                <button class="grid h-9 w-9 shrink-0 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100" @click="closeDetail">
                  <X class="h-5 w-5" />
                </button>
              </div>
            </div>

            <!-- Mobile tabs -->
            <div class="flex gap-1 border-b border-slate-100 px-5 pt-1">
              <button type="button" class="relative px-3 py-2.5 text-xs font-bold transition" :class="detailTab === 'settings' ? 'text-brand-700' : 'text-slate-500'" @click="detailTab = 'settings'">
                Settings
                <span v-if="detailTab === 'settings'" class="absolute inset-x-1 -bottom-px h-0.5 rounded-full bg-brand-600" />
              </button>
              <button type="button" class="relative flex items-center gap-1.5 px-3 py-2.5 text-xs font-bold transition" :class="detailTab === 'devices' ? 'text-brand-700' : 'text-slate-500'" @click="detailTab = 'devices'">
                Devices
                <span v-if="userDevices.length" class="grid h-4 min-w-4 place-items-center rounded-full bg-slate-100 px-1 text-[10px] font-black text-slate-600">{{ userDevices.length }}</span>
                <span v-if="detailTab === 'devices'" class="absolute inset-x-1 -bottom-px h-0.5 rounded-full bg-brand-600" />
              </button>
            </div>

            <div class="flex-1 overflow-y-auto overscroll-contain">
              <!-- Settings -->
              <div v-if="detailTab === 'settings'" class="space-y-4 p-5 pb-28">
                <div class="flex items-center gap-3 rounded-xl p-3.5" :class="selectedUser.active ? 'bg-emerald-50/80' : 'bg-rose-50/80'">
                  <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl" :class="selectedUser.active ? 'bg-emerald-100' : 'bg-rose-100'">
                    <ShieldCheck v-if="selectedUser.active" class="h-5 w-5 text-emerald-600" />
                    <ShieldX v-else class="h-5 w-5 text-rose-500" />
                  </div>
                  <div>
                    <p class="text-sm font-bold" :class="selectedUser.active ? 'text-emerald-800' : 'text-rose-800'">{{ selectedUser.active ? 'Active' : 'Inactive' }}</p>
                    <p class="text-[11px]" :class="selectedUser.active ? 'text-emerald-600' : 'text-rose-500'">Since {{ fmtDate(selectedUser.created_at) }}</p>
                  </div>
                </div>

                <fieldset>
                  <legend class="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500">Role</legend>
                  <select v-model="editForm.role" class="w-full rounded-xl border-0 bg-slate-50 py-2.5 text-sm font-medium ring-1 ring-slate-200/60 focus:bg-white focus:ring-2 focus:ring-brand-500">
                    <option v-for="role in props.roles" :key="role.code" :value="role.code">{{ role.name }}</option>
                  </select>
                  <div v-if="roleDescriptions[editForm.role]" class="mt-2 flex items-start gap-2 rounded-lg px-3 py-2" :class="[roleColor(editForm.role).light]">
                    <span class="mt-0.5 h-1.5 w-1.5 shrink-0 rounded-full" :class="roleColor(editForm.role).dot" />
                    <div class="min-w-0">
                      <p class="text-[11px] font-bold" :class="roleColor(editForm.role).text">{{ roleDescriptions[editForm.role].scope }} scope</p>
                      <p class="mt-0.5 text-[11px] leading-4 text-slate-500">{{ roleDescriptions[editForm.role].hint }}</p>
                    </div>
                  </div>
                </fieldset>

                <fieldset v-if="editForm.role === 'cpu_member'">
                  <legend class="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500">Represents as</legend>
                  <select v-model="editForm.cpu_discipline" class="w-full rounded-xl border-0 bg-slate-50 py-2.5 text-sm font-medium ring-1 ring-slate-200/60 focus:bg-white focus:ring-2 focus:ring-brand-500">
                    <option value="">Select discipline</option>
                    <option v-for="(label, code) in props.cpuDisciplines" :key="code" :value="code">{{ label }}</option>
                  </select>
                </fieldset>

                <fieldset v-if="editForm.role === 'fo_user'">
                  <legend class="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500">Assigned region</legend>
                  <select :value="editForm.region" aria-label="Assigned region" class="w-full rounded-xl border-0 bg-slate-50 py-2.5 text-sm font-medium ring-1 ring-slate-200/60 focus:bg-white focus:ring-2 focus:ring-brand-500" @change="setRegion(($event.target as HTMLSelectElement).value)">
                    <option value="">Select region</option><option v-for="region in locations?.regions ?? []" :key="region.code" :value="region.name">{{ region.name }}</option>
                  </select>
                  <p class="mt-2 text-[11px] leading-4 text-slate-500">Field Office users see aggregate data across all LGUs within their assigned region.</p>
                </fieldset>

                <fieldset v-if="needsLgu">
                  <legend class="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500">LGU Assignment</legend>
                  <p v-if="currentLguName(selectedUser.id)" class="mb-2 flex items-center gap-2 rounded-lg bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-700">
                    <MapPin class="h-3.5 w-3.5" />{{ officialLguName(currentLguName(selectedUser.id)) }}
                  </p>
                  <p v-else class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700">No LGU assigned - this user cannot access cases until assigned.</p>
                  <div v-if="props.lguUnits.length" class="flex gap-2">
                    <select v-model="editLgu.lgu_id" class="flex-1 rounded-xl border-0 bg-slate-50 py-2 text-sm ring-1 ring-slate-200/60 focus:bg-white focus:ring-2 focus:ring-brand-500">
                      <option value="">Select LGU</option>
                      <option v-for="lgu in props.lguUnits" :key="lgu.id" :value="lgu.id">{{ officialLguName(lgu) }}</option>
                    </select>
                    <button :disabled="!editLgu.lgu_id" class="rounded-xl bg-brand-50 px-4 py-2 text-sm font-bold text-brand-700 ring-1 ring-brand-200 hover:bg-brand-100 disabled:opacity-40" @click="assignLgu">Assign</button>
                  </div>
                  <div v-else class="rounded-lg border border-dashed border-slate-300 p-3 text-center">
                    <p class="text-xs text-slate-500">No LGUs registered yet.</p>
                    <Link :href="route('admin.lgus.index')" class="mt-1.5 inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-bold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50" @click="closeDetail">
                      <MapPin class="h-3 w-3" />Go to LGU registry
                    </Link>
                  </div>
                </fieldset>

                <div class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200/60">
                  <label class="flex cursor-pointer items-center justify-between">
                    <div>
                      <p class="text-sm font-bold text-slate-900">Account active</p>
                      <p class="text-[11px] text-slate-500">Deactivating revokes access</p>
                    </div>
                    <div class="relative">
                      <input v-model="editForm.active" type="checkbox" class="peer sr-only" />
                      <div class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-emerald-500" />
                      <div class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5" />
                    </div>
                  </label>
                </div>
                <div class="rounded-xl border border-rose-100 bg-rose-50/50 p-4">
                  <p class="text-sm font-bold text-rose-900">Delete account</p>
                  <p class="mt-1 text-[11px] leading-4 text-rose-700">Only unused accounts can be deleted. Accounts with casework or audit history must be deactivated.</p>
                  <button type="button" class="mt-3 min-h-10 rounded-xl border border-rose-200 bg-white px-3 text-xs font-bold text-rose-700 hover:bg-rose-50" @click="openDeleteUser">Delete account</button>
                </div>
              </div>

              <!-- Devices -->
              <div v-if="detailTab === 'devices'" class="p-5">
                <p v-if="devicesLoading" class="py-8 text-center text-sm text-slate-500">Loading…</p>
                <div v-else-if="!userDevices.length" class="flex flex-col items-center py-10 text-center">
                  <div class="grid h-14 w-14 place-items-center rounded-2xl bg-slate-100">
                    <Smartphone class="h-6 w-6 text-slate-500" />
                  </div>
                  <p class="mt-3 text-sm font-bold text-slate-500">No devices</p>
                  <p class="mt-1 text-xs text-slate-500">No offline devices registered.</p>
                </div>
                <div v-else class="space-y-2.5">
                  <div v-for="d in userDevices" :key="d.id" class="rounded-xl bg-slate-50 p-3.5 ring-1 ring-slate-200/60">
                    <div class="flex items-start justify-between gap-3">
                      <div class="flex items-start gap-3">
                        <div class="grid h-9 w-9 shrink-0 place-items-center rounded-lg" :class="d.revoked_at ? 'bg-rose-100 text-rose-400' : 'bg-brand-100 text-brand-600'">
                          <Monitor class="h-4 w-4" />
                        </div>
                        <div>
                          <p class="text-sm font-bold text-slate-800">{{ d.device_name || 'Unnamed' }}</p>
                          <p class="font-mono text-[11px] text-slate-500">{{ d.device_id.slice(0, 8) }}…</p>
                        </div>
                      </div>
                      <span v-if="d.revoked_at" class="rounded-lg bg-rose-100 px-2.5 py-1 text-[10px] font-bold text-rose-600">Revoked</span>
                      <button v-else class="rounded-lg bg-white px-3 py-1.5 text-[11px] font-bold text-rose-600 shadow-sm ring-1 ring-rose-200 hover:bg-rose-50" @click="adminRevokeDevice(d.id)">Revoke</button>
                    </div>
                    <p class="mt-2 text-[10px] text-slate-500">Last seen {{ d.last_seen_at ? fmtDateTime(d.last_seen_at) : 'never' }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Mobile sticky save -->
            <div v-if="detailTab === 'settings'" class="sticky bottom-0 shrink-0 border-t border-slate-100 bg-white px-5 py-4 shadow-[0_-4px_12px_rgba(0,0,0,.06)]">
              <button
                :disabled="editForm.processing"
                class="flex w-full items-center justify-center gap-2 rounded-2xl bg-brand-700 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-brand-700/20 transition-all hover:shadow-xl disabled:opacity-50"
                @click="saveUser"
              >
                <Check class="h-4 w-4" />{{ editForm.processing ? 'Saving…' : 'Save changes' }}
              </button>
            </div>
          </aside>
        </div>
      </Transition>
    </Teleport>
    <Teleport to="body"><Transition name="panel"><div v-if="confirmingDeactivation" class="fixed inset-0 z-[80] grid place-items-end bg-slate-950/50 p-4 sm:place-items-center" role="dialog" aria-modal="true"><div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl"><h2 class="text-lg font-extrabold text-slate-900">Deactivate account?</h2><p class="mt-2 text-sm leading-6 text-slate-600">This immediately removes {{ selectedUser?.name }} access and revokes their registered offline devices.</p><div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-bold" @click="cancelDeactivation">Cancel</button><button type="button" class="min-h-11 rounded-xl bg-rose-600 px-4 text-sm font-bold text-white" @click="confirmDeactivation">Deactivate account</button></div></div></div></Transition></Teleport><Teleport to="body"><Transition name="panel"><div v-if="deletingUser" class="fixed inset-0 z-[70] grid place-items-end bg-slate-950/50 p-4 sm:place-items-center" role="dialog" aria-modal="true"><form class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl" @submit.prevent="deleteUser"><h2 class="text-lg font-extrabold text-slate-900">Delete account?</h2><p class="mt-2 text-sm leading-6 text-slate-600">Delete <b>{{ deletingUser.name }}</b> only if it was created by mistake and has no operational history.</p><label class="mt-4 block text-sm font-bold text-slate-700">Type <span class="select-all">{{ deletingUser.email }}</span> to confirm<input v-model="deleteConfirmation" class="mt-1.5 block min-h-11 w-full rounded-xl border-slate-300" autocomplete="off" /></label><p v-if="deleteError" class="mt-2 text-sm text-rose-700">{{ deleteError }}</p><div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-bold" @click="closeDeleteUser">Cancel</button><button :disabled="deleteBusy || deleteConfirmation !== deletingUser.email" class="min-h-11 rounded-xl bg-rose-600 px-4 text-sm font-bold text-white disabled:opacity-40">{{ deleteBusy ? 'Deleting' : 'Delete account' }}</button></div></form></div></Transition></Teleport>
    <Teleport to="body">
      <Transition name="panel">
        <div v-if="confirmingFinalDelete" class="fixed inset-0 z-[90] grid place-items-end bg-slate-950/60 p-4 sm:place-items-center" role="dialog" aria-modal="true" aria-labelledby="final-delete-title">
          <section class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
            <h2 id="final-delete-title" class="text-lg font-extrabold text-slate-900">Permanently delete this account?</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">This cannot be undone. <b>{{ deletingUser?.name }}</b> will lose access immediately.</p>
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <button type="button" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-bold" :disabled="deleteBusy" @click="cancelFinalDelete">Go back</button>
              <button type="button" class="min-h-11 rounded-xl bg-rose-600 px-4 text-sm font-bold text-white disabled:opacity-40" :disabled="deleteBusy" @click="confirmDeleteUser">{{ deleteBusy ? 'Deleting' : 'Yes, delete account' }}</button>
            </div>
          </section>
        </div>
      </Transition>
    </Teleport>
  </AuthenticatedLayout>
</template>

<style scoped>
.panel-enter-active, .panel-leave-active { transition: all .3s cubic-bezier(.16, 1, .3, 1); }
.panel-enter-active aside, .panel-leave-active aside { transition: transform .3s cubic-bezier(.16, 1, .3, 1); }
.panel-enter-from, .panel-leave-to { opacity: 0; }
.panel-enter-from aside, .panel-leave-to aside { transform: translateX(100%); }

.detail-panel {
  animation: slideUp .3s cubic-bezier(.16, 1, .3, 1);
}
@keyframes slideUp {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
