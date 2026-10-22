import { ref, onMounted, onUnmounted } from 'vue';
export function useConnectivity() { const online=ref(navigator.onLine); const update=()=>online.value=navigator.onLine; onMounted(()=>{addEventListener('online',update);addEventListener('offline',update)}); onUnmounted(()=>{removeEventListener('online',update);removeEventListener('offline',update)}); return {online}; }
