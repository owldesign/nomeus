import { createBrowserRouter } from 'react-router-dom';
import Layout from '@/components/Layout';
import Status from '@/pages/Status';
import Placeholder from '@/pages/Placeholder';

export const router = createBrowserRouter([
  {
    path: '/',
    element: <Layout />,
    children: [
      { index: true, element: <Status /> },
      { path: 'sites', element: <Placeholder name="Sites" slice="1b" /> },
      { path: 'php', element: <Placeholder name="PHP" slice="1c" /> },
      { path: 'tasks', element: <Placeholder name="Tasks" slice="1c" /> },
      { path: 'services', element: <Placeholder name="Services" slice="phase 2" /> },
      { path: 'mail', element: <Placeholder name="Mail" slice="phase 3" /> },
      { path: 'logs', element: <Placeholder name="Logs" slice="phase 4" /> },
      { path: 'debug', element: <Placeholder name="Debug" slice="phase 5" /> },
    ],
  },
]);
