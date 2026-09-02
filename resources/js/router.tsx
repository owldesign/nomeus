import { createBrowserRouter } from 'react-router-dom';
import Layout from '@/components/Layout';
import Status from '@/pages/Status';
import Sites from '@/pages/Sites';
import Tasks from '@/pages/Tasks';
import Php from '@/pages/Php';
import Services from '@/pages/Services';
import Placeholder from '@/pages/Placeholder';

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
      { path: 'mail', element: <Placeholder name="Mail" slice="phase 3" /> },
      { path: 'logs', element: <Placeholder name="Logs" slice="phase 4" /> },
      { path: 'debug', element: <Placeholder name="Debug" slice="phase 5" /> },
    ],
  },
]);
