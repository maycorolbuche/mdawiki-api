import { Hono } from 'hono';
import { PageController } from '../controllers/PageController';
import type { AppEnv } from '../types';

export const pages = new Hono<AppEnv>();
pages.get('/', PageController.list);
pages.get('/:slug', PageController.show);
pages.post('/', PageController.store);
pages.put('/:slug', PageController.update);
pages.delete('/:slug', PageController.destroy);
