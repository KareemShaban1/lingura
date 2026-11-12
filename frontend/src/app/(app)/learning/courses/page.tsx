import type { Metadata } from "next";
import { CoursesView } from "@/features/courses/course-views";

export const metadata: Metadata = { title: "Courses" };

export default function CoursesPage() {
  return <CoursesView />;
}
