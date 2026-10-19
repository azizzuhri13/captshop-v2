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
                    DEFAULT: '#D92525',
                    dark: '#B81D1D',
                    light: '#E84141',
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
