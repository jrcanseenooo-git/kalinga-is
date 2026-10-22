import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Plus Jakarta Sans"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: { 50:'#f5f0ff', 100:'#ede4ff', 200:'#d5bcff', 300:'#c4a0ee', 400:'#a87dd9', 500:'#9f60cc', 600:'#8d5fcc', 700:'#7a4db5', 800:'#5a3a95', 900:'#2d1a5e' },
                kalinga: { purple:'#8d5fcc', blue:'#83cbdd', yellow:'#fffda6' },
            },
        },
    },

    plugins: [forms],
};
