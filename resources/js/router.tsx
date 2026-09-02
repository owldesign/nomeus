import { createBrowserRouter } from 'react-router-dom';
import Layout from '@/components/Layout';
import Status from '@/pages/Status';
import Sites from '@/pages/Sites';
import Tasks from '@/pages/Tasks';
import Php from '@/pages/Php';
import Services from '@/pages/Services';
import Mail from '@/pages/Mail';
import Logs from '@/pages/Logs';
import Debug from '@/pages/Debug';

export const router = createBrowserRouter([
  {
    path: '/',
    element: <Layout />,
    children: [
      { index: true, element: <Status /> },
      { path: 'sites', element: <Sites /> },
      { path: 'php', element: <Php /> },
      { path: 'tasks', element: <Tasks /> },
      { path: 'services', element: <Services /> },
      { path: 'mail', element: <Mail /> },
      { path: 'logs', element: <Logs /> },
      { path: 'debug', element: <Debug /> },
    ],
  },
]);
