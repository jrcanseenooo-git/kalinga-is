<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'
import { Head, useForm, router, Link } from '@inertiajs/vue3'
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue'

const props = defineProps<{
    method: 'email' | 'totp'
    hasTotp: boolean
    maskedEmail: string
    status?: string
    debugCode?: string
    uatCode?: string
}>()

type Mode = 'email' | 'totp' | 'recovery'
const mode = ref<Mode>(props.method)

const form = useForm({ code: '', recovery_code: '', method: props.method })
const resending = ref(false)
const statusMsg = ref(props.status || '')

const digitRefs = ref<HTMLInputElement[]>([])
const digits = ref<string[]>(['', '', '', '', '', ''])

const focusDigit = (index: number) => {
    nextTick(() => digitRefs.value[index]?.focus())
}

const onDigitInput = (index: number, event: Event) => {
    const input = event.target as HTMLInputElement
    const val = input.value.replace(/\D/g, '')

    if (val.length > 1) {
        const chars = val.slice(0, 6).split('')
        chars.forEach((c, i) => { if (i < 6) digits.value[i] = c })
        form.code = digits.value.join('')
        focusDigit(Math.min(chars.length, 5))
        return
    }

    digits.value[index] = val.slice(0, 1)
    form.code = digits.value.join('')

    if (val && index < 5) {
        focusDigit(index + 1)
    }
}

const onDigitKeydown = (index: number, event: KeyboardEvent) => {
    if (event.key === 'Backspace' && !digits.value[index] && index > 0) {
        digits.value[index - 1] = ''
        form.code = digits.value.join('')
        focusDigit(index - 1)
    }
}

const onDigitPaste = (event: ClipboardEvent) => {
    event.preventDefault()
    const text = (event.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, 6)
    text.split('').forEach((c, i) => { if (i < 6) digits.value[i] = c })
    form.code = digits.value.join('')
    focusDigit(Math.min(text.length, 5))
}

const clearDigits = () => {
    digits.value = ['', '', '', '', '', '']
    form.code = ''
}

const submit = () => {
    if (mode.value === 'recovery') {
        form.method = 'totp'
        form.code = ''
    } else {
        form.recovery_code = ''
        form.method = mode.value
    }
    form.post(route('two-factor.verify'), {
        onError: () => {
            clearDigits()
            nextTick(() => focusDigit(0))
        },
    })
}

const switchToEmail = () => {
    router.get(route('two-factor.challenge'), { via: 'email' }, { preserveState: false })
}

const resendCode = () => {
    resending.value = true
    router.post(route('two-factor.email-resend'), {}, {
        preserveState: false,
        onFinish: () => {
            resending.value = false
            countdown.value = 600
            if (!countdownTimer) {
                countdownTimer = setInterval(() => {
                    if (countdown.value > 0) countdown.value--
                    else if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null }
                }, 1000)
            }
        },
    })
}

const autoSubmitEnabled = ref(true)
let autoSubmitTimer: ReturnType<typeof setTimeout> | null = null
watch(() => form.code, (val) => {
    if (autoSubmitTimer) { clearTimeout(autoSubmitTimer); autoSubmitTimer = null }
    if (val.length === 6 && autoSubmitEnabled.value && mode.value !== 'recovery' && !form.processing) {
        autoSubmitTimer = setTimeout(() => {
            if (form.code.length === 6 && !form.processing) {
                submit()
            }
        }, 150)
    }
})

const countdown = ref(600)
let countdownTimer: ReturnType<typeof setInterval> | null = null
const countdownDisplay = () => {
    const m = Math.floor(countdown.value / 60)
    const s = countdown.value % 60
    return `${m}:${s.toString().padStart(2, '0')}`
}

onMounted(() => {
    if (mode.value !== 'recovery') {
        focusDigit(0)
    }
    if (mode.value === 'email') {
        countdownTimer = setInterval(() => {
            if (countdown.value > 0) countdown.value--
            else if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null }
        }, 1000)
    }
})

onUnmounted(() => {
    if (countdownTimer) clearInterval(countdownTimer)
    if (autoSubmitTimer) clearTimeout(autoSubmitTimer)
})
</script>

<template>
    <GuestLayout fit-viewport><Head title="Two-factor verification" />

        <!-- Icon -->
        <div class="flex justify-center">
            <div class="grid h-16 w-16 place-items-center rounded-2xl bg-gradient-to-br from-brand-600 to-brand-800 shadow-lg shadow-brand-700/25">
                <svg v-if="mode === 'email'" class="h-8 w-8 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                <svg v-else-if="mode === 'totp'" class="h-8 w-8 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1"/></svg>
                <svg v-else class="h-8 w-8 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
            </div>
        </div>

        <!-- Title -->
        <div class="mt-5 text-center">
            <h2 class="text-2xl font-extrabold tracking-tight text-slate-900">
                <template v-if="mode === 'recovery'">Recovery code</template>
                <template v-else>Verify your identity</template>
            </h2>
            <p class="mx-auto mt-2 max-w-[280px] text-sm leading-6 text-slate-500">
                <template v-if="mode === 'email' && uatCode">Enter the 6-digit UAT code displayed below. This temporary account is for fictional test data only.</template>
                <template v-else-if="mode === 'email'">Enter the 6-digit code we sent to <span class="font-semibold text-slate-700">{{ maskedEmail }}</span></template>
                <template v-else-if="mode === 'totp'">Enter the 6-digit code from your authenticator app</template>
                <template v-else>Enter one of your saved recovery codes</template>
            </p>
        </div>

        <!-- Status banner -->
        <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0 -translate-y-1" leave-active-class="transition duration-150" leave-to-class="opacity-0">
            <div v-if="statusMsg" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-center text-sm font-medium text-emerald-700">
                {{ statusMsg }}
            </div>
        </Transition>

        <!-- Dev mode code hint -->
        <div v-if="uatCode && mode === 'email'" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-center" data-testid="uat-code">
            <p class="text-xs font-bold text-amber-800">UAT test code</p>
            <p class="mt-1 font-mono text-xl font-bold tracking-[.35em] text-amber-900">{{ uatCode }}</p>
            <p class="mt-1 text-xs text-amber-800">For testing only. This on-screen code does not verify email ownership.</p>
        </div>
        <div v-if="debugCode && mode === 'email'" class="mt-4 rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-2.5 text-center">
            <p class="text-[11px] font-bold uppercase tracking-wider text-amber-600">Test Mode</p>
            <p class="mt-0.5 font-mono text-lg font-extrabold tracking-[.35em] text-amber-800">{{ debugCode }}</p>
        </div>

        <!-- Form -->
        <form @submit.prevent="submit" data-skip-generic-confirm="true" class="mt-6">

            <!-- 6-digit OTP input -->
            <div v-if="mode !== 'recovery'" class="space-y-3">
                <label class="block text-center text-xs font-bold uppercase tracking-wider text-slate-500">
                    {{ mode === 'email' ? 'Verification code' : 'Authentication code' }}
                </label>
                <div class="flex justify-center gap-2.5">
                    <input
                        v-for="(_, i) in 6"
                        :key="i"
                        :ref="(el) => { if (el) digitRefs[i] = el as HTMLInputElement }"
                        type="text"
                        inputmode="numeric"
                        maxlength="1"
                        :value="digits[i]"
                        autocomplete="one-time-code"
                        :aria-label="`Digit ${i + 1} of 6`"
                        class="h-14 w-11 rounded-xl border-slate-200 bg-white text-center font-mono text-xl font-bold text-slate-900 shadow-sm transition-all
                               focus:border-brand-500 focus:ring-4 focus:ring-brand-100 focus:shadow-brand-100/40
                               placeholder:text-slate-300"
                        :class="{ 'border-rose-300 ring-2 ring-rose-100': form.errors.code }"
                        placeholder="·"
                        @input="onDigitInput(i, $event)"
                        @keydown="onDigitKeydown(i, $event)"
                        @paste="onDigitPaste"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                </div>

                <!-- Error -->
                <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0 translate-y-1">
                    <div v-if="form.errors.code" class="flex items-center justify-center gap-1.5 text-sm font-medium text-rose-600">
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
                        {{ form.errors.code }}
                    </div>
                </Transition>

                <!-- Timer hint for email mode -->
                <p v-if="mode === 'email'" class="text-center text-xs" :class="countdown <= 60 ? 'text-rose-500 font-semibold' : 'text-slate-500'" aria-live="polite" aria-atomic="true">
                    <template v-if="countdown > 0">Code expires in {{ countdownDisplay() }}</template>
                    <template v-else>Code expired - please resend</template>
                </p>
            </div>

            <!-- Recovery code input -->
            <div v-else class="space-y-3">
                <label for="recovery_code" class="block text-center text-xs font-bold uppercase tracking-wider text-slate-500">Recovery code</label>
                <input
                    id="recovery_code"
                    v-model="form.recovery_code"
                    type="text"
                    class="block w-full rounded-xl border-slate-200 bg-white px-4 py-3.5 text-center font-mono text-sm shadow-sm transition focus:border-brand-500 focus:ring-4 focus:ring-brand-100"
                    required
                    autofocus
                    placeholder="Enter recovery code"
                />
                <InputError :message="form.errors.code" />
            </div>

            <!-- Submit button -->
            <button
                type="submit"
                :disabled="form.processing || (mode !== 'recovery' && form.code.length < 6)"
                class="mt-5 flex w-full items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-3.5 text-sm font-bold text-white shadow-lg shadow-brand-700/15 transition-all hover:bg-brand-800 hover:-translate-y-px disabled:opacity-40 disabled:hover:translate-y-0 disabled:cursor-not-allowed"
            >
                <svg v-if="form.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-20"/><path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                {{ form.processing ? 'Verifying…' : 'Verify & continue' }}
            </button>
        </form>

        <!-- Action links -->
        <div class="mt-5 space-y-1">
            <button v-if="mode === 'email'" type="button" :disabled="resending" @click="resendCode"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl py-2.5 text-sm font-semibold text-brand-600 transition hover:bg-brand-50 disabled:opacity-50">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M21 21v-5h-5"/></svg>
                {{ resending ? 'Sending…' : 'Resend code' }}
            </button>

            <button v-if="mode === 'email' && hasTotp" type="button" @click="mode = 'totp'; clearDigits(); nextTick(() => focusDigit(0))"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl py-2.5 text-sm font-semibold text-brand-600 transition hover:bg-brand-50">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Use authenticator app
            </button>

            <button v-if="mode === 'totp'" type="button" @click="switchToEmail"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl py-2.5 text-sm font-semibold text-brand-600 transition hover:bg-brand-50">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                Use email code instead
            </button>

            <button v-if="mode === 'totp'" type="button" @click="mode = 'recovery'; clearDigits()"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-700">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                Use recovery code
            </button>

            <button v-if="mode === 'recovery'" type="button" @click="mode = hasTotp ? 'totp' : 'email'; form.recovery_code = ''; nextTick(() => focusDigit(0))"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl py-2.5 text-sm font-semibold text-brand-600 transition hover:bg-brand-50">
                Back to {{ hasTotp ? 'authenticator' : 'email' }} code
            </button>
        </div>

        <!-- Sign out -->
        <div class="mt-4 border-t border-slate-100 pt-4">
            <Link :href="route('logout')" method="post" as="button" class="flex w-full items-center justify-center gap-1.5 rounded-xl py-2.5 text-sm text-slate-600 transition hover:bg-slate-50 hover:text-slate-600">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16,17 21,12 16,7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sign out
            </Link>
        </div>

    </GuestLayout>
</template>
