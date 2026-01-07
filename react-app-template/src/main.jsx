import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './styles.css';

const el = document.getElementById('casanova-portal-root');
if (el) createRoot(el).render(<App />);
