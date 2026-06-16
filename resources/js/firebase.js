import { initializeApp } from 'firebase/app';
import {
    getAuth, signInWithEmailAndPassword,
    signInWithPopup, GoogleAuthProvider, signOut,
} from 'firebase/auth';

const app = initializeApp({
    apiKey:     import.meta.env.VITE_FIREBASE_API_KEY,
    authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
    projectId:  import.meta.env.VITE_FIREBASE_PROJECT_ID,
    appId:      import.meta.env.VITE_FIREBASE_APP_ID,
});

const auth = getAuth(app);
const googleProvider = new GoogleAuthProvider();

export const firebaseAuth = {
    async emailLogin(email, password) {
        const cred = await signInWithEmailAndPassword(auth, email, password);
        return cred.user.getIdToken();
    },
    async googleLogin() {
        const cred = await signInWithPopup(auth, googleProvider);
        return cred.user.getIdToken();
    },
    async logout() {
        await signOut(auth);
    },
};