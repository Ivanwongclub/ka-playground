export type Json =
  | string
  | number
  | boolean
  | null
  | { [key: string]: Json | undefined }
  | Json[]

export type Database = {
  // Allows to automatically instantiate createClient with right options
  // instead of createClient<Database, { PostgrestVersion: 'XX' }>(URL, KEY)
  __InternalSupabase: {
    PostgrestVersion: "14.5"
  }
  public: {
    Tables: {
      cms_landing: {
        Row: {
          announcements: Json
          announcements_title: string
          categories_title: string
          featured_cta: string
          featured_eyebrow: string
          featured_programme_id: string | null
          hero_cta: string
          hero_subtitle: string
          hero_title: string
          id: number
          programmes_title: string
          stats: Json
          updated_at: string | null
        }
        Insert: {
          announcements: Json
          announcements_title: string
          categories_title: string
          featured_cta: string
          featured_eyebrow: string
          featured_programme_id?: string | null
          hero_cta: string
          hero_subtitle: string
          hero_title: string
          id?: number
          programmes_title: string
          stats: Json
          updated_at?: string | null
        }
        Update: {
          announcements?: Json
          announcements_title?: string
          categories_title?: string
          featured_cta?: string
          featured_eyebrow?: string
          featured_programme_id?: string | null
          hero_cta?: string
          hero_subtitle?: string
          hero_title?: string
          id?: number
          programmes_title?: string
          stats?: Json
          updated_at?: string | null
        }
        Relationships: [
          {
            foreignKeyName: "cms_landing_featured_programme_id_fkey"
            columns: ["featured_programme_id"]
            isOneToOne: false
            referencedRelation: "programmes"
            referencedColumns: ["id"]
          },
        ]
      }
      enrolments: {
        Row: {
          completed_modules: number | null
          enrolled_at: string | null
          enrolled_by: string | null
          grade: string | null
          id: string
          last_quiz_name: string | null
          last_quiz_score: number | null
          programme_id: string
          progress_percent: number | null
          status: Database["public"]["Enums"]["enrolment_status"]
          student_id: string
          total_modules: number | null
        }
        Insert: {
          completed_modules?: number | null
          enrolled_at?: string | null
          enrolled_by?: string | null
          grade?: string | null
          id?: string
          last_quiz_name?: string | null
          last_quiz_score?: number | null
          programme_id: string
          progress_percent?: number | null
          status?: Database["public"]["Enums"]["enrolment_status"]
          student_id: string
          total_modules?: number | null
        }
        Update: {
          completed_modules?: number | null
          enrolled_at?: string | null
          enrolled_by?: string | null
          grade?: string | null
          id?: string
          last_quiz_name?: string | null
          last_quiz_score?: number | null
          programme_id?: string
          progress_percent?: number | null
          status?: Database["public"]["Enums"]["enrolment_status"]
          student_id?: string
          total_modules?: number | null
        }
        Relationships: [
          {
            foreignKeyName: "enrolments_enrolled_by_fkey"
            columns: ["enrolled_by"]
            isOneToOne: false
            referencedRelation: "users"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "enrolments_programme_id_fkey"
            columns: ["programme_id"]
            isOneToOne: false
            referencedRelation: "programmes"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "enrolments_student_id_fkey"
            columns: ["student_id"]
            isOneToOne: false
            referencedRelation: "students"
            referencedColumns: ["id"]
          },
        ]
      }
      notes: {
        Row: {
          author_id: string | null
          content: string
          created_at: string | null
          id: string
          student_id: string
        }
        Insert: {
          author_id?: string | null
          content: string
          created_at?: string | null
          id?: string
          student_id: string
        }
        Update: {
          author_id?: string | null
          content?: string
          created_at?: string | null
          id?: string
          student_id?: string
        }
        Relationships: [
          {
            foreignKeyName: "notes_author_id_fkey"
            columns: ["author_id"]
            isOneToOne: false
            referencedRelation: "users"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "notes_student_id_fkey"
            columns: ["student_id"]
            isOneToOne: false
            referencedRelation: "students"
            referencedColumns: ["id"]
          },
        ]
      }
      programme_content: {
        Row: {
          certification: string | null
          class_size: string | null
          curriculum: Json | null
          format: string | null
          gallery_labels: Json | null
          programme_id: string
          stats: Json | null
          testimonials: Json | null
          why_join: Json | null
        }
        Insert: {
          certification?: string | null
          class_size?: string | null
          curriculum?: Json | null
          format?: string | null
          gallery_labels?: Json | null
          programme_id: string
          stats?: Json | null
          testimonials?: Json | null
          why_join?: Json | null
        }
        Update: {
          certification?: string | null
          class_size?: string | null
          curriculum?: Json | null
          format?: string | null
          gallery_labels?: Json | null
          programme_id?: string
          stats?: Json | null
          testimonials?: Json | null
          why_join?: Json | null
        }
        Relationships: [
          {
            foreignKeyName: "programme_content_programme_id_fkey"
            columns: ["programme_id"]
            isOneToOne: true
            referencedRelation: "programmes"
            referencedColumns: ["id"]
          },
        ]
      }
      programmes: {
        Row: {
          age_range: string
          brand_color: string
          capacity: number
          category: string
          created_at: string | null
          description: string
          duration_weeks: number
          enrolled_count: number
          external_lms_url: string | null
          featured: boolean
          id: string
          organiser: string
          period_end: string | null
          period_start: string | null
          progress_updates: string
          provider_short: string
          sign_in_method: string
          status: Database["public"]["Enums"]["programme_status"]
          tagline: string | null
          title: string
        }
        Insert: {
          age_range: string
          brand_color: string
          capacity: number
          category: string
          created_at?: string | null
          description: string
          duration_weeks: number
          enrolled_count?: number
          external_lms_url?: string | null
          featured?: boolean
          id: string
          organiser: string
          period_end?: string | null
          period_start?: string | null
          progress_updates?: string
          provider_short: string
          sign_in_method?: string
          status?: Database["public"]["Enums"]["programme_status"]
          tagline?: string | null
          title: string
        }
        Update: {
          age_range?: string
          brand_color?: string
          capacity?: number
          category?: string
          created_at?: string | null
          description?: string
          duration_weeks?: number
          enrolled_count?: number
          external_lms_url?: string | null
          featured?: boolean
          id?: string
          organiser?: string
          period_end?: string | null
          period_start?: string | null
          progress_updates?: string
          provider_short?: string
          sign_in_method?: string
          status?: Database["public"]["Enums"]["programme_status"]
          tagline?: string | null
          title?: string
        }
        Relationships: []
      }
      student_relationships: {
        Row: {
          created_at: string | null
          custom_permissions: Json | null
          id: string
          related_user_id: string
          role: Database["public"]["Enums"]["user_role"]
          student_id: string
        }
        Insert: {
          created_at?: string | null
          custom_permissions?: Json | null
          id?: string
          related_user_id: string
          role: Database["public"]["Enums"]["user_role"]
          student_id: string
        }
        Update: {
          created_at?: string | null
          custom_permissions?: Json | null
          id?: string
          related_user_id?: string
          role?: Database["public"]["Enums"]["user_role"]
          student_id?: string
        }
        Relationships: [
          {
            foreignKeyName: "student_relationships_related_user_id_fkey"
            columns: ["related_user_id"]
            isOneToOne: false
            referencedRelation: "users"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "student_relationships_student_id_fkey"
            columns: ["student_id"]
            isOneToOne: false
            referencedRelation: "students"
            referencedColumns: ["id"]
          },
        ]
      }
      students: {
        Row: {
          auth_user_id: string | null
          bio: string | null
          created_at: string | null
          dob: string | null
          full_name: string
          full_name_zh: string | null
          gender: string | null
          id: string
          photo_url: string | null
          region: string | null
          updated_at: string | null
        }
        Insert: {
          auth_user_id?: string | null
          bio?: string | null
          created_at?: string | null
          dob?: string | null
          full_name: string
          full_name_zh?: string | null
          gender?: string | null
          id?: string
          photo_url?: string | null
          region?: string | null
          updated_at?: string | null
        }
        Update: {
          auth_user_id?: string | null
          bio?: string | null
          created_at?: string | null
          dob?: string | null
          full_name?: string
          full_name_zh?: string | null
          gender?: string | null
          id?: string
          photo_url?: string | null
          region?: string | null
          updated_at?: string | null
        }
        Relationships: []
      }
      tasks: {
        Row: {
          assigned_to: string | null
          created_at: string | null
          due_date: string | null
          id: string
          status: string | null
          student_id: string
          title: string
        }
        Insert: {
          assigned_to?: string | null
          created_at?: string | null
          due_date?: string | null
          id?: string
          status?: string | null
          student_id: string
          title: string
        }
        Update: {
          assigned_to?: string | null
          created_at?: string | null
          due_date?: string | null
          id?: string
          status?: string | null
          student_id?: string
          title?: string
        }
        Relationships: [
          {
            foreignKeyName: "tasks_assigned_to_fkey"
            columns: ["assigned_to"]
            isOneToOne: false
            referencedRelation: "users"
            referencedColumns: ["id"]
          },
          {
            foreignKeyName: "tasks_student_id_fkey"
            columns: ["student_id"]
            isOneToOne: false
            referencedRelation: "students"
            referencedColumns: ["id"]
          },
        ]
      }
      users: {
        Row: {
          created_at: string | null
          email: string
          full_name: string
          full_name_zh: string | null
          id: string
          language: string | null
          region: string | null
          role: Database["public"]["Enums"]["user_role"]
        }
        Insert: {
          created_at?: string | null
          email: string
          full_name: string
          full_name_zh?: string | null
          id: string
          language?: string | null
          region?: string | null
          role: Database["public"]["Enums"]["user_role"]
        }
        Update: {
          created_at?: string | null
          email?: string
          full_name?: string
          full_name_zh?: string | null
          id?: string
          language?: string | null
          region?: string | null
          role?: Database["public"]["Enums"]["user_role"]
        }
        Relationships: []
      }
    }
    Views: {
      [_ in never]: never
    }
    Functions: {
      can_view_student: { Args: { s_id: string }; Returns: boolean }
      is_admin:
        | { Args: never; Returns: boolean }
        | { Args: { _uid: string }; Returns: boolean }
    }
    Enums: {
      enrolment_status: "active" | "completed" | "paused" | "cancelled"
      programme_status: "Open" | "Registering" | "Coming Soon" | "Closed"
      user_role: "admin" | "school" | "teacher" | "parent" | "student"
    }
    CompositeTypes: {
      [_ in never]: never
    }
  }
}

type DatabaseWithoutInternals = Omit<Database, "__InternalSupabase">

type DefaultSchema = DatabaseWithoutInternals[Extract<keyof Database, "public">]

export type Tables<
  DefaultSchemaTableNameOrOptions extends
    | keyof (DefaultSchema["Tables"] & DefaultSchema["Views"])
    | { schema: keyof DatabaseWithoutInternals },
  TableName extends DefaultSchemaTableNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof (DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"] &
        DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Views"])
    : never = never,
> = DefaultSchemaTableNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? (DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"] &
      DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Views"])[TableName] extends {
      Row: infer R
    }
    ? R
    : never
  : DefaultSchemaTableNameOrOptions extends keyof (DefaultSchema["Tables"] &
        DefaultSchema["Views"])
    ? (DefaultSchema["Tables"] &
        DefaultSchema["Views"])[DefaultSchemaTableNameOrOptions] extends {
        Row: infer R
      }
      ? R
      : never
    : never

export type TablesInsert<
  DefaultSchemaTableNameOrOptions extends
    | keyof DefaultSchema["Tables"]
    | { schema: keyof DatabaseWithoutInternals },
  TableName extends DefaultSchemaTableNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"]
    : never = never,
> = DefaultSchemaTableNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"][TableName] extends {
      Insert: infer I
    }
    ? I
    : never
  : DefaultSchemaTableNameOrOptions extends keyof DefaultSchema["Tables"]
    ? DefaultSchema["Tables"][DefaultSchemaTableNameOrOptions] extends {
        Insert: infer I
      }
      ? I
      : never
    : never

export type TablesUpdate<
  DefaultSchemaTableNameOrOptions extends
    | keyof DefaultSchema["Tables"]
    | { schema: keyof DatabaseWithoutInternals },
  TableName extends DefaultSchemaTableNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"]
    : never = never,
> = DefaultSchemaTableNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[DefaultSchemaTableNameOrOptions["schema"]]["Tables"][TableName] extends {
      Update: infer U
    }
    ? U
    : never
  : DefaultSchemaTableNameOrOptions extends keyof DefaultSchema["Tables"]
    ? DefaultSchema["Tables"][DefaultSchemaTableNameOrOptions] extends {
        Update: infer U
      }
      ? U
      : never
    : never

export type Enums<
  DefaultSchemaEnumNameOrOptions extends
    | keyof DefaultSchema["Enums"]
    | { schema: keyof DatabaseWithoutInternals },
  EnumName extends DefaultSchemaEnumNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[DefaultSchemaEnumNameOrOptions["schema"]]["Enums"]
    : never = never,
> = DefaultSchemaEnumNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[DefaultSchemaEnumNameOrOptions["schema"]]["Enums"][EnumName]
  : DefaultSchemaEnumNameOrOptions extends keyof DefaultSchema["Enums"]
    ? DefaultSchema["Enums"][DefaultSchemaEnumNameOrOptions]
    : never

export type CompositeTypes<
  PublicCompositeTypeNameOrOptions extends
    | keyof DefaultSchema["CompositeTypes"]
    | { schema: keyof DatabaseWithoutInternals },
  CompositeTypeName extends PublicCompositeTypeNameOrOptions extends {
    schema: keyof DatabaseWithoutInternals
  }
    ? keyof DatabaseWithoutInternals[PublicCompositeTypeNameOrOptions["schema"]]["CompositeTypes"]
    : never = never,
> = PublicCompositeTypeNameOrOptions extends {
  schema: keyof DatabaseWithoutInternals
}
  ? DatabaseWithoutInternals[PublicCompositeTypeNameOrOptions["schema"]]["CompositeTypes"][CompositeTypeName]
  : PublicCompositeTypeNameOrOptions extends keyof DefaultSchema["CompositeTypes"]
    ? DefaultSchema["CompositeTypes"][PublicCompositeTypeNameOrOptions]
    : never

export const Constants = {
  public: {
    Enums: {
      enrolment_status: ["active", "completed", "paused", "cancelled"],
      programme_status: ["Open", "Registering", "Coming Soon", "Closed"],
      user_role: ["admin", "school", "teacher", "parent", "student"],
    },
  },
} as const
