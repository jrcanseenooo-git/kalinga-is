import axios from 'axios';

/**
 * Phone notifications (Web Push). The push itself carries no content; the
 * service worker fetches the newest notifications from Kalinga and shows them.
 * On iPhone this works only after "Add to Home Screen" (iOS 16.4+).
 */
export type PushState = 'unsupported' | 'needs-install' | 'blocked' | 'off' | 'on';

const toBytes = (base64url: string) => {
  const padded = base64url.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (base64url.length % 4)) % 4);
  return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
};
const standalone = () => window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent);

async function registration(): Promise<ServiceWorkerRegistration> {
  return (await navigator.serviceWorker.getRegistration('/')) ?? navigator.serviceWorker.register('/sw.js');
}

export async function pushState(): Promise<PushState> {
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return isIos() && !standalone() ? 'needs-install' : 'unsupported';
  if (Notification.permission === 'denied') return 'blocked';
  const reg = await navigator.serviceWorker.getRegistration('/');
  return (await reg?.pushManager.getSubscription()) ? 'on' : 'off';
}

export async function turnOnPush(publicKey: string): Promise<PushState> {
  if ((await Notification.requestPermission()) !== 'granted') return 'blocked';
  const reg = await registration();
  await navigator.serviceWorker.ready;
  const subscription = (await reg.pushManager.getSubscription()) ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toBytes(publicKey) }));
  const label = /android/i.test(navigator.userAgent) ? 'Android phone' : isIos() ? 'iPhone or iPad' : 'Computer';
  await axios.post(route('push.subscribe'), { endpoint: subscription.endpoint, device_label: label });
  return 'on';
}

export async function turnOffPush(): Promise<PushState> {
  const reg = await navigator.serviceWorker.getRegistration('/');
  const subscription = await reg?.pushManager.getSubscription();
  if (subscription) {
    await axios.delete(route('push.unsubscribe'), { data: { endpoint: subscription.endpoint } }).catch(() => undefined);
    await subscription.unsubscribe();
  }
  return 'off';
}
