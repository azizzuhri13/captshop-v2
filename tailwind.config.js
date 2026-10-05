import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './app/**/*.php',
        './routes/**/*.php',
    ],

    theme: {
        extend: {
            colors: {
                primary: {
                    DEFAULT: '#6C3CE1',
                    dark: '#5930B8',
                    light: '#8A5FEB',
                },
                secondary: '#10B981',
                accent: '#F59E0B',
            },
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                heading: ['Poppins', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [],
};
